<?php

namespace Tests\Feature;

use App\Actions\GenerateCandidateBatch;
use App\Contracts\ContentIntelligence;
use App\Filament\Resources\SourceChannels\Pages\CreateSourceChannel;
use App\Filament\Resources\SourceChannels\Pages\EditSourceChannel;
use App\Jobs\SyncJsonCollectionSourceJob;
use App\Jobs\VerifySourceChannelAccessJob;
use App\Models\ContentPlan;
use App\Models\PlannedPost;
use App\Models\Publication;
use App\Models\Source;
use App\Models\SourceGroup;
use App\Models\SourcePost;
use App\Models\User;
use App\SourceType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class UniversalSourceManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_json_collection_source_can_be_created_and_attached_to_a_group(): void
    {
        Queue::fake();
        $user = User::factory()->create(['is_active' => true]);
        $group = SourceGroup::factory()->create(['name' => 'Фильмы']);

        $this->actingAs($user);

        Livewire::test(CreateSourceChannel::class)
            ->fillForm([
                'type' => SourceType::JsonCollection->value,
                'title' => 'Qokka — фильмы',
                'weight' => 1,
                'sourceGroups' => [$group->id],
                'is_active' => true,
                'endpoint_url' => 'https://93.184.216.34/api/v1/publications',
                'settings' => ['lookback_hours' => 24, 'limit' => 100],
                'credentials' => ['authorization' => 'qk_test_secret'],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $source = Source::query()->where('title', 'Qokka — фильмы')->firstOrFail();

        $this->assertSame(SourceType::JsonCollection, $source->type);
        $this->assertSame('qk_test_secret', $source->authorization());
        $this->assertNull($source->username);
        $this->assertNull($source->telegram_peer_id);
        $this->assertDatabaseHas('source_group_source', [
            'source_id' => $source->id,
            'source_group_id' => $group->id,
        ]);
        $this->assertStringNotContainsString(
            'qk_test_secret',
            (string) DB::table('sources')->where('id', $source->id)->value('credentials'),
        );
        Queue::assertPushed(
            SyncJsonCollectionSourceJob::class,
            fn (SyncJsonCollectionSourceJob $job): bool => $job->sourceId === $source->id,
        );
        Queue::assertNotPushed(VerifySourceChannelAccessJob::class);
    }

    public function test_blank_authorization_on_edit_preserves_the_encrypted_secret(): void
    {
        Queue::fake();
        $user = User::factory()->create(['is_active' => true]);
        $source = Source::factory()->jsonCollection()->create([
            'credentials' => ['authorization' => 'qk_existing_secret'],
        ]);

        $this->actingAs($user);

        Livewire::test(EditSourceChannel::class, ['record' => $source->id])
            ->fillForm([
                'endpoint_url' => 'https://93.184.216.34/api/v1/publications/serials',
                'credentials' => ['authorization' => ''],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $source->refresh();

        $this->assertSame('qk_existing_secret', $source->authorization());
        $this->assertSame(
            'https://93.184.216.34/api/v1/publications/serials',
            $source->endpoint_url,
        );
        Queue::assertPushed(SyncJsonCollectionSourceJob::class);
        Queue::assertNotPushed(VerifySourceChannelAccessJob::class);
    }

    public function test_content_plan_receives_telegram_and_json_materials_from_one_group(): void
    {
        $group = SourceGroup::factory()->create();
        $telegram = Source::factory()->create(['title' => 'Telegram']);
        $json = Source::factory()->jsonCollection()->create(['title' => 'Qokka']);
        $group->sources()->attach([$telegram->id, $json->id]);
        $publication = Publication::factory()->create(['source_group_id' => $group->id]);
        $plan = ContentPlan::factory()->create(['publication_id' => $publication->id]);
        $telegramPost = SourcePost::factory()->create([
            'source_id' => $telegram->id,
            'text' => 'Обычная новость',
        ]);
        $collectionPost = SourcePost::factory()->create([
            'source_id' => $json->id,
            'text' => 'Подборка фильмов',
            'metrics' => ['available' => false],
            'metadata' => [
                'content_kind' => 'collection',
                'collection' => ['collection_id' => 'collection-1', 'title' => 'Кино', 'items' => []],
            ],
        ]);
        $fake = new class($collectionPost->id) implements ContentIntelligence
        {
            /** @var list<int> */
            public array $receivedPostIds = [];

            public function __construct(private readonly int $selectedPostId) {}

            public function rankAndCluster(ContentPlan $contentPlan, Collection $sourcePosts): array
            {
                $this->receivedPostIds = $sourcePosts->pluck('id')->map(
                    fn (mixed $id): int => (int) $id,
                )->values()->all();

                return ['clusters' => [[
                    'source_post_ids' => [$this->selectedPostId],
                    'title' => 'Подборка фильмов',
                    'summary' => 'Несколько фильмов для просмотра.',
                    'score' => 90,
                    'score_breakdown' => [],
                    'selection_reason' => 'Подходит теме публикации.',
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

        app(GenerateCandidateBatch::class)->handle($plan);

        $this->assertEqualsCanonicalizing(
            [$telegramPost->id, $collectionPost->id],
            $fake->receivedPostIds,
        );
        $this->assertDatabaseHas('source_post_story_candidate', [
            'source_post_id' => $collectionPost->id,
            'is_primary' => true,
        ]);
    }
}
