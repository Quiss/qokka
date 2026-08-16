<?php

namespace Tests\Feature;

use App\Actions\ApprovePlannedPost;
use App\Actions\GenerateCandidateBatch;
use App\Actions\PlaceCandidateInPlan;
use App\Actions\ReplenishContentPlanCandidates;
use App\Actions\RequestPlannedPostRewrite;
use App\CandidateStatus;
use App\ContentPlanStatus;
use App\Contracts\ContentIntelligence;
use App\DeliveryStatus;
use App\Filament\Resources\ContentPlans\Pages\EditContentPlan;
use App\Filament\Resources\ContentPlans\RelationManagers\PlannedPostsRelationManager;
use App\Filament\Resources\ContentPlans\RelationManagers\StoryCandidatesRelationManager;
use App\Filament\Resources\PlannedPosts\Pages\EditPlannedPost;
use App\Filament\Resources\SourceChannels\Pages\EditSourceChannel;
use App\Filament\Resources\SourceChannels\RelationManagers\PostsRelationManager;
use App\Jobs\DownloadMediaAssetJob;
use App\Jobs\GenerateCandidateBatchJob;
use App\Jobs\ReplenishContentPlanCandidatesJob;
use App\Jobs\RewritePlannedPostJob;
use App\MediaType;
use App\Models\ContentPlan;
use App\Models\Delivery;
use App\Models\MediaAsset;
use App\Models\PlannedPost;
use App\Models\Publication;
use App\Models\Source;
use App\Models\SourceGroup;
use App\Models\SourcePost;
use App\Models\StoryCandidate;
use App\Models\TelegramAccount;
use App\Models\TelegramOwnerCommand;
use App\Models\User;
use App\PlannedPostStatus;
use App\TelegramOwnerCommandType;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\ViewField;
use Filament\Schemas\Components\View;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class EditorialPlanEnhancementsTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejected_post_is_replaced_by_highest_scoring_reserve_in_the_same_slot(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $slot = now()->addDay()->startOfMinute();
        $plan = ContentPlan::factory()->create([
            'status' => ContentPlanStatus::FinalReview,
            'slot_schedule' => [$slot->toIso8601String()],
        ]);
        $selected = StoryCandidate::factory()->create([
            'content_plan_id' => $plan->id,
            'status' => CandidateStatus::Selected,
        ]);
        $post = PlannedPost::factory()->create([
            'content_plan_id' => $plan->id,
            'story_candidate_id' => $selected->id,
            'scheduled_at' => $slot,
        ]);
        $lowerReserve = $this->reserveCandidate($plan, 60);
        $bestReserve = $this->reserveCandidate($plan, 95);

        $replacement = app(ApprovePlannedPost::class)->reject($post, $user, 'Тема не подходит');

        $this->assertSame(PlannedPostStatus::Cancelled, $post->fresh()->status);
        $this->assertSame(CandidateStatus::Rejected, $selected->fresh()->status);
        $this->assertSame($bestReserve->id, $replacement->story_candidate_id);
        $this->assertSame($post->id, $replacement->replaces_planned_post_id);
        $this->assertTrue($replacement->scheduled_at->equalTo($slot));
        $this->assertSame(CandidateStatus::Selected, $bestReserve->fresh()->status);
        $this->assertSame(CandidateStatus::Reserve, $lowerReserve->fresh()->status);
        $this->assertSame(ContentPlanStatus::Rewriting, $plan->fresh()->status);
        Queue::assertPushed(RewritePlannedPostJob::class, 1);
    }

    public function test_plan_requests_manual_replenishment_when_reserve_is_empty(): void
    {
        $user = User::factory()->create();
        $plan = ContentPlan::factory()->create([
            'status' => ContentPlanStatus::FinalReview,
            'slot_schedule' => [now()->addDay()->toIso8601String()],
        ]);
        $candidate = StoryCandidate::factory()->create([
            'content_plan_id' => $plan->id,
            'status' => CandidateStatus::Selected,
        ]);
        $post = PlannedPost::factory()->create([
            'content_plan_id' => $plan->id,
            'story_candidate_id' => $candidate->id,
        ]);

        $result = app(ApprovePlannedPost::class)->reject($post, $user, 'Отклонено');

        $this->assertSame($post->id, $result->id);
        $this->assertSame(PlannedPostStatus::Cancelled, $post->fresh()->status);
        $this->assertSame(ContentPlanStatus::NeedsCandidates, $plan->fresh()->status);
    }

    public function test_repeat_rewrite_keeps_versions_and_ignores_stale_ai_results(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $plan = ContentPlan::factory()->create(['status' => ContentPlanStatus::FinalReview]);
        $candidate = StoryCandidate::factory()->create(['content_plan_id' => $plan->id]);
        $post = PlannedPost::factory()->create([
            'content_plan_id' => $plan->id,
            'story_candidate_id' => $candidate->id,
            'text' => 'Первая версия',
            'status' => PlannedPostStatus::FinalReview,
        ]);
        $action = app(RequestPlannedPostRewrite::class);
        $action->handle($post, $user, 'Сделай короче');

        $this->assertSame(1, $post->fresh()->rewrite_generation);
        $this->assertSame(PlannedPostStatus::Rewriting, $post->fresh()->status);
        $this->assertDatabaseHas('planned_post_revisions', [
            'planned_post_id' => $post->id,
            'version' => 1,
            'text' => 'Первая версия',
        ]);

        $intelligence = $this->fakeIntelligence('Вторая версия');
        (new RewritePlannedPostJob($post->id, 1, 'Сделай короче', $user->id, true))->handle($intelligence);

        $this->assertSame('Вторая версия', $post->fresh()->text);
        $this->assertDatabaseHas('planned_post_revisions', [
            'planned_post_id' => $post->id,
            'version' => 2,
            'instruction' => 'Сделай короче',
        ]);

        $action->handle($post->fresh(), $user, 'Ещё короче');
        (new RewritePlannedPostJob($post->id, 1, 'Устаревшая инструкция', $user->id, true))
            ->handle($this->fakeIntelligence('Устаревший результат'));

        $this->assertSame('Вторая версия', $post->fresh()->text);
        $this->assertSame(2, $post->fresh()->rewrite_generation);
        $this->assertSame(PlannedPostStatus::Rewriting, $post->fresh()->status);
        $this->assertDatabaseMissing('planned_post_revisions', ['text' => 'Устаревший результат']);
    }

    public function test_repeat_rewrite_revokes_approval_and_cancels_pending_delivery(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $plan = ContentPlan::factory()->create(['status' => ContentPlanStatus::Ready]);
        $candidate = StoryCandidate::factory()->create(['content_plan_id' => $plan->id]);
        $post = PlannedPost::factory()->create([
            'content_plan_id' => $plan->id,
            'story_candidate_id' => $candidate->id,
            'status' => PlannedPostStatus::Approved,
            'approved_by' => $user->id,
            'approved_at' => now(),
        ]);
        $delivery = Delivery::factory()->create([
            'planned_post_id' => $post->id,
            'status' => DeliveryStatus::Pending,
        ]);

        app(RequestPlannedPostRewrite::class)->handle($post, $user);

        $this->assertSame(PlannedPostStatus::Rewriting, $post->fresh()->status);
        $this->assertNull($post->fresh()->approved_by);
        $this->assertSame(DeliveryStatus::Cancelled, $delivery->fresh()->status);
        $this->assertSame(ContentPlanStatus::Rewriting, $plan->fresh()->status);
    }

    public function test_replenished_candidate_can_fill_a_vacant_slot(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $slot = now()->addDay()->startOfMinute();
        $plan = ContentPlan::factory()->create([
            'status' => ContentPlanStatus::NeedsCandidates,
            'slot_schedule' => [$slot->toIso8601String()],
        ]);
        $candidate = StoryCandidate::factory()->create([
            'content_plan_id' => $plan->id,
            'status' => CandidateStatus::Pending,
        ]);
        $source = SourcePost::factory()->create();
        $candidate->sourcePosts()->attach($source, ['is_primary' => true]);

        $plannedPost = app(PlaceCandidateInPlan::class)->handle($candidate, $user);

        $this->assertSame(CandidateStatus::Selected, $candidate->fresh()->status);
        $this->assertTrue($plannedPost->scheduled_at->equalTo($slot));
        $this->assertSame(ContentPlanStatus::Rewriting, $plan->fresh()->status);
        Queue::assertPushed(RewritePlannedPostJob::class, 1);
    }

    public function test_replenishment_excludes_seen_posts_and_marks_candidates_older_than_one_day(): void
    {
        $group = SourceGroup::factory()->create();
        $channel = Source::factory()->create();
        $group->sources()->attach($channel);
        $publication = Publication::factory()->create(['source_group_id' => $group->id]);
        $plan = ContentPlan::factory()->create([
            'publication_id' => $publication->id,
            'status' => ContentPlanStatus::NeedsCandidates,
        ]);
        $seenPost = SourcePost::factory()->create([
            'source_id' => $channel->id,
            'posted_at' => now()->subHour(),
        ]);
        $oldPost = SourcePost::factory()->create([
            'source_id' => $channel->id,
            'posted_at' => now()->subHours(30),
        ]);
        $seenCandidate = StoryCandidate::factory()->create(['content_plan_id' => $plan->id]);
        $seenCandidate->sourcePosts()->attach($seenPost, ['is_primary' => true]);
        $fake = new class($oldPost->id) implements ContentIntelligence
        {
            /** @var list<int> */
            public array $receivedIds = [];

            public function __construct(private readonly int $oldPostId) {}

            public function rankAndCluster(ContentPlan $contentPlan, Collection $sourcePosts): array
            {
                $this->receivedIds = array_values(
                    $sourcePosts->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
                );

                return ['clusters' => [[
                    'source_post_ids' => [$this->oldPostId],
                    'title' => 'Добранная новость',
                    'summary' => 'Событие за пределами суток',
                    'score' => 80,
                    'score_breakdown' => [],
                    'selection_reason' => 'Подходит для свободного слота',
                    'risk_flags' => [],
                ]]];
            }

            public function rewrite(PlannedPost $plannedPost, ?string $instruction = null): array
            {
                return ['text' => ''];
            }

            public function reviewPlan(ContentPlan $contentPlan): array
            {
                return ['items' => [], 'duplicate_groups' => []];
            }
        };
        $this->app->instance(ContentIntelligence::class, $fake);

        app(GenerateCandidateBatch::class)->handle(
            $plan,
            append: true,
            lookbackHours: 48,
            targetOverride: 1,
        );

        $this->assertSame([$oldPost->id], $fake->receivedIds);
        $candidate = $plan->storyCandidates()->where('title', 'Добранная новость')->firstOrFail();
        $this->assertContains('older_than_24h', $candidate->risk_flags);
    }

    public function test_candidate_review_can_replenish_all_rejected_candidates(): void
    {
        Queue::fake();
        $plan = ContentPlan::factory()->create([
            'status' => ContentPlanStatus::CandidateReview,
            'candidate_target' => 9,
        ]);
        StoryCandidate::factory()->count(9)->create([
            'content_plan_id' => $plan->id,
            'status' => CandidateStatus::Rejected,
        ]);

        $this->assertTrue(app(ReplenishContentPlanCandidates::class)->handle($plan));
        $this->assertSame(ContentPlanStatus::Generating, $plan->fresh()->status);
        Queue::assertPushed(
            ReplenishContentPlanCandidatesJob::class,
            fn (ReplenishContentPlanCandidatesJob $job): bool => $job->contentPlanId === $plan->id
                && $job->candidateTarget === 9
                && $job->completionStatus === ContentPlanStatus::CandidateReview,
        );
    }

    public function test_candidate_review_replenishment_job_returns_plan_to_candidate_review(): void
    {
        $group = SourceGroup::factory()->create();
        $publication = Publication::factory()->create(['source_group_id' => $group->id]);
        $plan = ContentPlan::factory()->create([
            'publication_id' => $publication->id,
            'status' => ContentPlanStatus::Generating,
        ]);
        $job = new ReplenishContentPlanCandidatesJob(
            $plan->id,
            9,
            ContentPlanStatus::CandidateReview,
        );

        $job->handle(app(GenerateCandidateBatch::class));

        $this->assertSame(ContentPlanStatus::CandidateReview, $plan->fresh()->status);
    }

    public function test_ai_timeout_configuration_preserves_safe_queue_ordering(): void
    {
        $job = new GenerateCandidateBatchJob(1);

        $this->assertSame(300, config('services.openrouter.timeout'));
        $this->assertSame(330, $job->timeout);
        $this->assertSame(360, config('horizon.environments.production.supervisor-ai.timeout'));
        $this->assertSame(420, config('queue.connections.redis.retry_after'));
        $this->assertGreaterThan($job->timeout, config('horizon.environments.production.supervisor-ai.timeout'));
        $this->assertGreaterThan(config('horizon.environments.production.supervisor-ai.timeout'), config('queue.connections.redis.retry_after'));
    }

    public function test_admin_plan_page_renders_compact_rewrite_workflow_actions(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $plan = ContentPlan::factory()->create(['status' => ContentPlanStatus::FinalReview]);
        $source = SourcePost::factory()->create(['text' => 'Подробности из исходного Telegram-поста']);
        $sourceMedia = MediaAsset::factory()->for($source, 'mediable')->create();
        $candidate = StoryCandidate::factory()->create([
            'content_plan_id' => $plan->id,
            'title' => 'Проверяем компактную строку публикации',
            'status' => CandidateStatus::Selected,
        ]);
        $candidate->sourcePosts()->attach($source, ['is_primary' => true]);
        $plannedPost = PlannedPost::factory()->create([
            'content_plan_id' => $plan->id,
            'story_candidate_id' => $candidate->id,
            'status' => PlannedPostStatus::FinalReview,
        ]);

        $this->actingAs($user);

        $component = Livewire::test(PlannedPostsRelationManager::class, [
            'ownerRecord' => $plan,
            'pageClass' => EditContentPlan::class,
        ])
            ->assertSee('Проверяем компактную строку публикации')
            ->assertSee('Рерайт ещё раз')
            ->assertSee('Открыть')
            ->mountAction(TestAction::make('open')->table($plannedPost))
            ->assertActionMounted(TestAction::make('open')->table($plannedPost))
            ->assertActionDataSet(['text' => $plannedPost->text])
            ->assertSchemaComponentExists(
                'media_asset_ids',
                checkComponentUsing: fn ($component): bool => $component instanceof ViewField
                    && $component->getView() === 'filament.forms.components.media-picker'
                    && $component->getViewData()['assets']->contains('id', $sourceMedia->id)
                    && $component->getViewData()['maxBytes'] === 500 * 1024 * 1024,
            );
        $relationManager = $component->instance();

        if (! $relationManager instanceof PlannedPostsRelationManager) {
            $this->fail('Expected the planned posts relation manager.');
        }

        $mountedSchemaName = $relationManager->getMountedActionSchemaName();
        $this->assertNotNull($mountedSchemaName);
        $schemaComponents = $relationManager->getSchema($mountedSchemaName)?->getComponents();
        $this->assertInstanceOf(MarkdownEditor::class, $schemaComponents[0] ?? null);
        $this->assertInstanceOf(FileUpload::class, $schemaComponents[1] ?? null);
        $this->assertSame(10, $schemaComponents[1]->getMaxFiles());
        $this->assertContains('video/mp4', $schemaComponents[1]->getAcceptedFileTypes());
        $this->assertInstanceOf(ViewField::class, $schemaComponents[2] ?? null);
        $this->assertInstanceOf(View::class, $schemaComponents[3] ?? null);

        $this->view('filament.content-plans.planned-post-details', [
            'record' => $plannedPost->load([
                'mediaAssets',
                'revisions.requestedBy',
                'storyCandidate.sourcePosts.source',
                'storyCandidate.sourcePosts.mediaAssets',
            ]),
        ])
            ->assertSee('Источники кластера · 1')
            ->assertSee('Откройте, чтобы сверить рерайт')
            ->assertSee('Подробности из исходного Telegram-поста')
            ->assertSee('История рерайтов · 0')
            ->assertDontSee('Медиа публикации');
    }

    public function test_planned_post_schedule_is_displayed_in_the_publication_timezone(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $publication = Publication::factory()->create(['timezone' => 'Europe/Moscow']);
        $plan = ContentPlan::factory()->for($publication)->create([
            'status' => ContentPlanStatus::FinalReview,
        ]);
        $candidate = StoryCandidate::factory()->create([
            'content_plan_id' => $plan->id,
            'status' => CandidateStatus::Selected,
        ]);
        $plannedPost = PlannedPost::factory()->create([
            'content_plan_id' => $plan->id,
            'story_candidate_id' => $candidate->id,
            'scheduled_at' => Carbon::parse('2026-07-25 06:00:00', 'UTC'),
        ]);

        $this->actingAs($user);

        Livewire::test(PlannedPostsRelationManager::class, [
            'ownerRecord' => $plan,
            'pageClass' => EditContentPlan::class,
        ])->assertTableColumnFormattedStateSet('scheduled_at', '25.07, 09:00', $plannedPost);

        Livewire::test(EditPlannedPost::class, ['record' => $plannedPost->getRouteKey()])
            ->assertSchemaComponentExists(
                'scheduled_at',
                checkComponentUsing: fn ($component): bool => $component instanceof DateTimePicker
                    && $component->getTimezone() === 'Europe/Moscow',
            );
    }

    public function test_approval_modal_is_only_shown_for_posts_with_risks_and_comment_is_optional(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['is_active' => true]);
        $plan = ContentPlan::factory()->create([
            'status' => ContentPlanStatus::FinalReview,
            'slot_schedule' => [
                now()->addHour()->toIso8601String(),
                now()->addHours(2)->toIso8601String(),
            ],
        ]);
        $safeCandidate = StoryCandidate::factory()->create([
            'content_plan_id' => $plan->id,
            'status' => CandidateStatus::Selected,
        ]);
        $riskyCandidate = StoryCandidate::factory()->create([
            'content_plan_id' => $plan->id,
            'status' => CandidateStatus::Selected,
        ]);
        $safePost = PlannedPost::factory()->create([
            'content_plan_id' => $plan->id,
            'story_candidate_id' => $safeCandidate->id,
            'scheduled_at' => now()->addHour(),
            'status' => PlannedPostStatus::FinalReview,
            'risk_flags' => [],
        ]);
        $riskyPost = PlannedPost::factory()->create([
            'content_plan_id' => $plan->id,
            'story_candidate_id' => $riskyCandidate->id,
            'scheduled_at' => now()->addHours(2),
            'status' => PlannedPostStatus::Blocked,
            'risk_flags' => ['source_conflict'],
        ]);
        Storage::disk('local')->put('telegram/video.mp4', 'original-video');
        $sourcePost = SourcePost::factory()->create();
        $sourceVideo = MediaAsset::factory()->for($sourcePost, 'mediable')->create([
            'type' => MediaType::Video,
            'path' => 'telegram/video.mp4',
            'mime_type' => 'video/mp4',
        ]);
        MediaAsset::factory()->for($safePost, 'mediable')->create([
            'origin_media_asset_id' => $sourceVideo->id,
            'type' => MediaType::Video,
            'path' => null,
            'downloaded_at' => null,
            'mime_type' => 'video/mp4',
        ]);

        $this->actingAs($user);

        Livewire::test(PlannedPostsRelationManager::class, [
            'ownerRecord' => $plan,
            'pageClass' => EditContentPlan::class,
        ])
            ->mountAction(TestAction::make('approve')->table($safePost))
            ->assertActionNotMounted()
            ->assertNotified('Публикация одобрена');

        $this->assertSame(PlannedPostStatus::Approved, $safePost->fresh()->status);

        Livewire::test(PlannedPostsRelationManager::class, [
            'ownerRecord' => $plan,
            'pageClass' => EditContentPlan::class,
        ])
            ->mountAction(TestAction::make('approve')->table($riskyPost))
            ->assertActionMounted(TestAction::make('approve')->table($riskyPost))
            ->assertMountedActionModalSee('источники расходятся в деталях')
            ->callMountedAction()
            ->assertNotified('Публикация одобрена');

        $this->assertSame(PlannedPostStatus::Approved, $riskyPost->fresh()->status);
        $this->assertNull($riskyPost->fresh()->override_reason);
    }

    public function test_approval_action_explains_failed_media_and_retry_is_an_explicit_action(): void
    {
        Queue::fake();
        $user = User::factory()->create(['is_active' => true]);
        $plan = ContentPlan::factory()->create([
            'status' => ContentPlanStatus::FinalReview,
            'slot_schedule' => [now()->addHour()->toIso8601String()],
        ]);
        $candidate = StoryCandidate::factory()->create([
            'content_plan_id' => $plan->id,
            'status' => CandidateStatus::Selected,
        ]);
        $account = TelegramAccount::factory()->create();
        $channel = Source::factory()->create([
            'collector_telegram_account_id' => $account->id,
        ]);
        $sourcePost = SourcePost::factory()->create(['source_id' => $channel->id]);
        $sourceMessage = $sourcePost->messages()->create([
            'source_id' => $channel->id,
            'telegram_account_id' => $account->id,
            'external_message_id' => 101,
            'entities' => [],
            'metrics' => [],
            'raw_payload' => [],
            'posted_at' => now(),
        ]);
        $origin = MediaAsset::factory()->for($sourcePost, 'mediable')->create([
            'source_message_id' => $sourceMessage->id,
            'type' => MediaType::Video,
            'path' => null,
            'downloaded_at' => null,
            'mime_type' => 'video/mp4',
        ]);
        $plannedPost = PlannedPost::factory()->create([
            'content_plan_id' => $plan->id,
            'story_candidate_id' => $candidate->id,
            'scheduled_at' => now()->addHour(),
            'status' => PlannedPostStatus::FinalReview,
            'risk_flags' => [],
        ]);
        $video = MediaAsset::factory()->for($plannedPost, 'mediable')->create([
            'source_message_id' => $sourceMessage->id,
            'origin_media_asset_id' => $origin->id,
            'type' => MediaType::Video,
            'path' => null,
            'downloaded_at' => null,
            'failed_at' => now(),
            'mime_type' => 'video/mp4',
        ]);
        $this->actingAs($user);

        Livewire::test(PlannedPostsRelationManager::class, [
            'ownerRecord' => $plan,
            'pageClass' => EditContentPlan::class,
        ])
            ->mountAction(TestAction::make('approve')->table($plannedPost))
            ->assertActionNotMounted()
            ->assertNotified('Публикация не одобрена');

        $this->assertSame(PlannedPostStatus::FinalReview, $plannedPost->fresh()->status);
        Queue::assertNotPushed(DownloadMediaAssetJob::class);

        Livewire::test(PlannedPostsRelationManager::class, [
            'ownerRecord' => $plan,
            'pageClass' => EditContentPlan::class,
        ])
            ->mountAction(TestAction::make('retry_media_download')->table($plannedPost))
            ->callMountedAction()
            ->assertNotified('Повторная загрузка медиа поставлена в очередь');

        $this->assertNotNull($video);
        $this->assertDatabaseHas('telegram_owner_commands', [
            'telegram_account_id' => $account->id,
            'type' => TelegramOwnerCommandType::DownloadMedia->value,
            'status' => 'pending',
        ]);
        $this->assertSame(
            $origin->id,
            TelegramOwnerCommand::query()->latest('id')->firstOrFail()->payload['media_asset_id'],
        );
    }

    public function test_partially_moderated_candidate_batch_can_be_queued_for_regeneration_from_admin(): void
    {
        Queue::fake();
        $user = User::factory()->create(['is_active' => true]);
        $plan = ContentPlan::factory()->create([
            'status' => ContentPlanStatus::CandidateReview,
            'generated_at' => now(),
        ]);
        StoryCandidate::factory()->create([
            'content_plan_id' => $plan->id,
            'status' => CandidateStatus::Pending,
        ]);
        StoryCandidate::factory()->create([
            'content_plan_id' => $plan->id,
            'status' => CandidateStatus::Approved,
        ]);
        StoryCandidate::factory()->create([
            'content_plan_id' => $plan->id,
            'status' => CandidateStatus::Rejected,
        ]);
        $this->actingAs($user);

        Livewire::test(EditContentPlan::class, ['record' => $plan->getRouteKey()])
            ->assertActionVisible('regenerate')
            ->callAction('regenerate');

        $this->assertSame(ContentPlanStatus::Generating, $plan->fresh()->status);
        Queue::assertPushed(
            GenerateCandidateBatchJob::class,
            fn (GenerateCandidateBatchJob $job): bool => $job->contentPlanId === $plan->id,
        );
    }

    public function test_source_posts_table_exposes_downloaded_media_and_post_details(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $channel = Source::factory()->create(['title' => 'Новости Петербурга']);
        $sourcePost = SourcePost::factory()->create([
            'source_id' => $channel->id,
            'text' => 'Пост с фотографией из Telegram',
            'views' => 15_200,
            'reactions' => 430,
            'posted_at' => now()->subHour(),
        ]);
        MediaAsset::factory()->for($sourcePost, 'mediable')->create();

        $this->actingAs($user);

        Livewire::test(PostsRelationManager::class, [
            'ownerRecord' => $channel,
            'pageClass' => EditSourceChannel::class,
        ])
            ->assertSee('Пост с фотографией из Telegram')
            ->assertSee('1 медиа')
            ->assertSee('Открыть')
            ->mountAction(TestAction::make('open')->table($sourcePost))
            ->assertActionMounted(TestAction::make('open')->table($sourcePost));

        $this->view('filament.source-channels.source-post-details', [
            'record' => $sourcePost->load(['source', 'mediaAssets']),
        ])
            ->assertSee('Новости Петербурга')
            ->assertSee('Пост с фотографией из Telegram')
            ->assertSee('Медиа')
            ->assertSee('Фото');
    }

    public function test_source_post_with_failed_media_exposes_an_explicit_retry_action(): void
    {
        Queue::fake();
        $user = User::factory()->create(['is_active' => true]);
        $account = TelegramAccount::factory()->create();
        $channel = Source::factory()->create([
            'collector_telegram_account_id' => $account->id,
        ]);
        $sourcePost = SourcePost::factory()->create([
            'source_id' => $channel->id,
            'posted_at' => now()->subHour(),
        ]);
        $sourceMessage = $sourcePost->messages()->create([
            'source_id' => $channel->id,
            'telegram_account_id' => $account->id,
            'external_message_id' => 102,
            'entities' => [],
            'metrics' => [],
            'raw_payload' => [],
            'posted_at' => now(),
        ]);
        $mediaAsset = MediaAsset::factory()->for($sourcePost, 'mediable')->create([
            'source_message_id' => $sourceMessage->id,
            'path' => null,
            'downloaded_at' => null,
            'failed_at' => now(),
            'metadata' => ['download_error' => 'Telegram file size mismatch.'],
        ]);
        $this->actingAs($user);

        Livewire::test(PostsRelationManager::class, [
            'ownerRecord' => $channel,
            'pageClass' => EditSourceChannel::class,
        ])
            ->assertActionVisible(TestAction::make('retry_media_download')->table($sourcePost))
            ->callAction(TestAction::make('retry_media_download')->table($sourcePost))
            ->assertNotified('Повторная загрузка медиа поставлена в очередь');

        $mediaAsset->refresh();
        $this->assertNull($mediaAsset->failed_at);
        $this->assertArrayNotHasKey('download_error', $mediaAsset->metadata ?? []);
        $this->assertDatabaseHas('telegram_owner_commands', [
            'telegram_account_id' => $account->id,
            'type' => TelegramOwnerCommandType::DownloadMedia->value,
            'status' => 'pending',
        ]);
        $this->assertSame(
            $mediaAsset->id,
            TelegramOwnerCommand::query()->latest('id')->firstOrFail()->payload['media_asset_id'],
        );
    }

    public function test_candidate_review_uses_compact_rows_and_opens_cluster_with_source_media(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $plan = ContentPlan::factory()->create(['status' => ContentPlanStatus::CandidateReview]);
        $source = SourcePost::factory()->create(['text' => 'Детали события из первичного источника']);
        MediaAsset::factory()->for($source, 'mediable')->create();
        $candidate = StoryCandidate::factory()->create([
            'content_plan_id' => $plan->id,
            'title' => 'Новость для первого этапа отбора',
            'summary' => 'Краткое описание события для редактора',
            'score' => 87,
            'score_breakdown' => ['freshness' => 9.5, 'reach' => 8],
            'ai_reason' => 'Высокая актуальность и хороший охват.',
            'risk_flags' => ['unsupported_claim'],
            'status' => CandidateStatus::Pending,
        ]);
        $candidate->sourcePosts()->attach($source, ['is_primary' => true]);

        $this->actingAs($user);

        Livewire::test(StoryCandidatesRelationManager::class, [
            'ownerRecord' => $plan,
            'pageClass' => EditContentPlan::class,
        ])
            ->assertSee('Новость для первого этапа отбора')
            ->assertSee('87 / 100')
            ->assertSee('Есть неподтверждённое утверждение')
            ->assertSee('Открыть')
            ->assertSee('Одобрить')
            ->mountAction(TestAction::make('open')->table($candidate))
            ->assertActionMounted(TestAction::make('open')->table($candidate));

        $this->view('filament.content-plans.story-candidate-details', [
            'record' => $candidate->load([
                'sourcePosts.source',
                'sourcePosts.mediaAssets',
            ]),
        ])
            ->assertSee('Суть новости')
            ->assertSee('Почему ИИ выбрал новость')
            ->assertSee('Источники кластера')
            ->assertSee('Детали события из первичного источника')
            ->assertSee('Основной источник')
            ->assertSee('Фото')
            ->assertSee('Есть неподтверждённое утверждение')
            ->assertDontSee('unsupported_claim');

        $this->assertSame(['unsupported_claim'], $candidate->fresh()->risk_flags);
    }

    public function test_candidate_review_moves_rejected_news_down_and_offers_to_replenish_the_deficit(): void
    {
        Queue::fake();
        $user = User::factory()->create(['is_active' => true]);
        $plan = ContentPlan::factory()->create([
            'status' => ContentPlanStatus::CandidateReview,
            'candidate_target' => 9,
        ]);
        $lowerPending = StoryCandidate::factory()->create([
            'content_plan_id' => $plan->id,
            'title' => 'Ожидает решения с низкой оценкой',
            'status' => CandidateStatus::Pending,
            'score' => 20,
        ]);
        $higherPending = StoryCandidate::factory()->create([
            'content_plan_id' => $plan->id,
            'title' => 'Ожидает решения с высокой оценкой',
            'status' => CandidateStatus::Pending,
            'score' => 80,
        ]);
        $rejectedCandidates = StoryCandidate::factory()->count(7)->create([
            'content_plan_id' => $plan->id,
            'status' => CandidateStatus::Rejected,
        ]);
        $expectedOrder = collect([$higherPending, $lowerPending])
            ->concat($rejectedCandidates->sortByDesc('score')->values());
        $this->actingAs($user);
        $replenishAction = TestAction::make('replenishCandidates')->table();

        Livewire::test(StoryCandidatesRelationManager::class, [
            'ownerRecord' => $plan,
            'pageClass' => EditContentPlan::class,
        ])
            ->assertCanSeeTableRecords($expectedOrder, inOrder: true)
            ->assertSee('Отклонена')
            ->assertSeeHtml('bg-danger-50/70')
            ->assertActionVisible($replenishAction)
            ->assertActionHasLabel($replenishAction, 'Добрать новости (7)')
            ->callAction($replenishAction)
            ->assertNotified('Добор 7 новостей поставлен в очередь');

        $this->assertSame(ContentPlanStatus::Generating, $plan->fresh()->status);
        Queue::assertPushed(
            ReplenishContentPlanCandidatesJob::class,
            fn (ReplenishContentPlanCandidatesJob $job): bool => $job->contentPlanId === $plan->id
                && $job->candidateTarget === 7
                && $job->completionStatus === ContentPlanStatus::CandidateReview,
        );
    }

    private function reserveCandidate(ContentPlan $plan, float $score): StoryCandidate
    {
        $candidate = StoryCandidate::factory()->create([
            'content_plan_id' => $plan->id,
            'status' => CandidateStatus::Reserve,
            'score' => $score,
        ]);
        $source = SourcePost::factory()->create();
        $candidate->sourcePosts()->attach($source, ['is_primary' => true]);

        return $candidate;
    }

    private function fakeIntelligence(string $rewrite): ContentIntelligence
    {
        return new class($rewrite) implements ContentIntelligence
        {
            public function __construct(private readonly string $rewrite) {}

            public function rankAndCluster(ContentPlan $contentPlan, Collection $sourcePosts): array
            {
                return ['clusters' => []];
            }

            public function rewrite(PlannedPost $plannedPost, ?string $instruction = null): array
            {
                return ['text' => $this->rewrite, 'risk_flags' => []];
            }

            public function reviewPlan(ContentPlan $contentPlan): array
            {
                return ['items' => [], 'duplicate_groups' => []];
            }
        };
    }
}
