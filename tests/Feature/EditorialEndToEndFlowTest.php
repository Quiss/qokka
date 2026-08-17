<?php

namespace Tests\Feature;

use App\Actions\ApprovePlannedPost;
use App\Actions\ApproveStoryCandidate;
use App\Actions\BuildContentPlan;
use App\Actions\GenerateCandidateBatch;
use App\ContentPlanStatus;
use App\Contracts\ContentIntelligence;
use App\DeliveryStatus;
use App\Jobs\PublishDeliveryJob;
use App\Jobs\ReviewContentPlanJob;
use App\Jobs\RewritePlannedPostJob;
use App\Models\ContentPlan;
use App\Models\Delivery;
use App\Models\Destination;
use App\Models\PlannedPost;
use App\Models\Publication;
use App\Models\Source;
use App\Models\SourceGroup;
use App\Models\SourcePost;
use App\Models\StoryCandidate;
use App\Models\User;
use App\PlannedPostStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EditorialEndToEndFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_news_flows_from_source_group_to_published_telegram_post(): void
    {
        Queue::fake();
        $group = SourceGroup::factory()->create(['name' => 'Про Питер']);
        $channel = Source::factory()->create(['title' => 'Еда Питера']);
        $group->sources()->attach($channel);
        $sourcePost = SourcePost::factory()->create([
            'source_id' => $channel->id,
            'text' => 'В Петербурге открылось новое общественное пространство',
            'posted_at' => now()->subHour(),
        ]);
        $publication = Publication::factory()->create([
            'source_group_id' => $group->id,
            'tone_prompt' => 'Коротко, живо и без канцелярита.',
            'publish_window_start' => '23:59',
            'publish_window_end' => '23:59',
            'reserve_multiplier' => 1,
        ]);
        Destination::factory()->create([
            'publication_id' => $publication->id,
            'external_id' => '@my_spb_channel',
        ]);
        $plan = ContentPlan::factory()->create([
            'publication_id' => $publication->id,
            'plan_date' => now()->addDay()->toDateString(),
            'status' => ContentPlanStatus::Generating,
        ]);
        $user = User::factory()->create();
        $intelligence = new class($sourcePost->id) implements ContentIntelligence
        {
            public function __construct(private readonly int $sourcePostId) {}

            public function rankAndCluster(ContentPlan $contentPlan, Collection $sourcePosts): array
            {
                return ['clusters' => [[
                    'source_post_ids' => [$this->sourcePostId],
                    'title' => 'Новое пространство Петербурга',
                    'summary' => 'Краткое описание новости',
                    'score' => 92,
                    'score_breakdown' => ['engagement' => 90],
                    'selection_reason' => 'Свежая городская новость',
                    'risk_flags' => [],
                ]]];
            }

            public function rewrite(PlannedPost $plannedPost, ?string $instruction = null): array
            {
                return ['text' => 'В Петербурге появилось новое общественное пространство.', 'risk_flags' => []];
            }

            public function reviewPlan(ContentPlan $contentPlan): array
            {
                return [
                    'items' => $contentPlan->plannedPosts->map(fn ($post): array => [
                        'planned_post_id' => $post->id,
                        'risk_flags' => [],
                    ])->all(),
                    'duplicate_groups' => [],
                ];
            }
        };
        $this->app->instance(ContentIntelligence::class, $intelligence);

        app(GenerateCandidateBatch::class)->handle($plan);
        $candidate = $plan->fresh()->storyCandidates()->firstOrFail();
        app(ApproveStoryCandidate::class)->approve($candidate, $user);
        app(BuildContentPlan::class)->handle($plan->fresh());
        $plannedPost = $plan->fresh()->plannedPosts()->firstOrFail();
        (new RewritePlannedPostJob($plannedPost->id))->handle($intelligence);
        (new ReviewContentPlanJob($plan->id))->handle($intelligence);
        app(ApprovePlannedPost::class)->approve($plannedPost->fresh(), $user);

        $delivery = $plannedPost->fresh()->deliveries()->firstOrFail();
        $plannedPost->update([
            'scheduled_at' => now()->subMinute(),
            'status' => PlannedPostStatus::Approved,
        ]);
        $delivery->update(['next_attempt_at' => now()->subMinute()]);
        config(['services.telegram.bot_token' => 'publisher-token']);
        Http::fake([
            'https://api.telegram.org/botpublisher-token/sendMessage' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 501],
            ]),
        ]);

        app()->call([(new PublishDeliveryJob($delivery->id)), 'handle']);

        $this->assertSame(PlannedPostStatus::Published, $plannedPost->fresh()->status);
        $this->assertSame(ContentPlanStatus::Completed, $plan->fresh()->status);
        $this->assertSame(['501'], $delivery->fresh()->external_message_ids);
        Http::assertSentCount(1);
    }

    public function test_ai_review_never_reverses_an_existing_manual_approval(): void
    {
        $publication = Publication::factory()->create();
        $destination = Destination::factory()->create(['publication_id' => $publication->id]);
        $plan = ContentPlan::factory()->create([
            'publication_id' => $publication->id,
            'status' => ContentPlanStatus::Rewriting,
        ]);
        $user = User::factory()->create();
        $approvedAt = now()->subMinute()->startOfSecond();
        $approvedPost = PlannedPost::factory()->create([
            'content_plan_id' => $plan->id,
            'story_candidate_id' => StoryCandidate::factory()->create(['content_plan_id' => $plan->id])->id,
            'status' => PlannedPostStatus::Approved,
            'risk_flags' => ['source_conflict'],
            'ai_review_status' => 'blocked',
            'approved_by' => $user->id,
            'approved_at' => $approvedAt,
            'override_by' => $user->id,
            'override_reason' => 'Проверено вручную',
        ]);
        $reviewablePost = PlannedPost::factory()->create([
            'content_plan_id' => $plan->id,
            'story_candidate_id' => StoryCandidate::factory()->create(['content_plan_id' => $plan->id])->id,
            'status' => PlannedPostStatus::FinalReview,
        ]);
        $delivery = Delivery::factory()->create([
            'planned_post_id' => $approvedPost->id,
            'destination_id' => $destination->id,
            'status' => DeliveryStatus::Pending,
        ]);
        $intelligence = new class implements ContentIntelligence
        {
            public function rankAndCluster(ContentPlan $contentPlan, Collection $sourcePosts): array
            {
                return ['clusters' => []];
            }

            public function rewrite(PlannedPost $plannedPost, ?string $instruction = null): array
            {
                return ['text' => 'Готовый текст'];
            }

            public function reviewPlan(ContentPlan $contentPlan): array
            {
                return [
                    'items' => $contentPlan->plannedPosts->map(fn (PlannedPost $post): array => [
                        'planned_post_id' => $post->id,
                        'risk_flags' => [],
                    ])->all(),
                    'duplicate_groups' => [],
                ];
            }
        };

        (new ReviewContentPlanJob($plan->id))->handle($intelligence);

        $approvedPost->refresh();
        $this->assertSame(PlannedPostStatus::Approved, $approvedPost->status);
        $this->assertSame(['source_conflict'], $approvedPost->risk_flags);
        $this->assertSame('blocked', $approvedPost->ai_review_status);
        $this->assertSame($user->id, $approvedPost->approved_by);
        $this->assertTrue($approvedPost->approved_at?->equalTo($approvedAt));
        $this->assertSame($user->id, $approvedPost->override_by);
        $this->assertSame('Проверено вручную', $approvedPost->override_reason);
        $this->assertSame(DeliveryStatus::Pending, $delivery->fresh()->status);
        $this->assertSame(PlannedPostStatus::FinalReview, $reviewablePost->fresh()->status);
    }
}
