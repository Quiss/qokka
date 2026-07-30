<?php

namespace Tests\Feature;

use App\Actions\IngestTelegramUpdate;
use App\Actions\PurgeSourceChannelContent;
use App\Contracts\MadelineClient;
use App\Jobs\SyncSourceChannelStatisticsJob;
use App\Models\ContentPlan;
use App\Models\MediaAsset;
use App\Models\PlannedPost;
use App\Models\SourceChannel;
use App\Models\SourceGroup;
use App\Models\SourceMessage;
use App\Models\SourcePost;
use App\Models\StoryCandidate;
use App\Models\TelegramAccount;
use App\Models\TelegramOwnerCommand;
use App\Services\MadelineClientPool;
use App\Services\TelegramMessagePayloadFactory;
use App\Services\TelegramOwnerCommandExecutor;
use App\TelegramOwnerCommandType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class SourceStatisticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_horizon_has_no_madeline_client_pool(): void
    {
        $this->assertFalse(class_exists(MadelineClientPool::class));
        $this->assertFalse($this->app->bound(MadelineClientPool::class));
    }

    public function test_raw_telegram_metrics_include_reaction_breakdown(): void
    {
        $account = TelegramAccount::factory()->create();
        $channel = SourceChannel::factory()->create();

        $payload = app(TelegramMessagePayloadFactory::class)->fromRawMessage(
            $account,
            $channel,
            [
                '_' => 'message',
                'id' => 17,
                'date' => now()->timestamp,
                'message' => 'Новость',
                'views' => 1200,
                'forwards' => 14,
                'replies' => ['replies' => 7],
                'reactions' => ['results' => [
                    ['reaction' => ['_' => 'reactionEmoji', 'emoticon' => '🔥'], 'count' => 25],
                    ['reaction' => ['_' => 'reactionEmoji', 'emoticon' => '👍'], 'count' => 11],
                ]],
            ],
        );

        $this->assertSame(1200, $payload['metrics']['views']);
        $this->assertSame(14, $payload['metrics']['forwards']);
        $this->assertSame(36, $payload['metrics']['reactions']);
        $this->assertSame(7, $payload['metrics']['comments']);
        $this->assertSame(['🔥' => 25, '👍' => 11], $payload['metrics']['reaction_breakdown']);
    }

    public function test_partial_metric_updates_preserve_post_content_and_other_counters(): void
    {
        $account = TelegramAccount::factory()->create();
        $channel = SourceChannel::factory()->create([
            'telegram_peer_id' => -100123,
            'username' => null,
            'collector_telegram_account_id' => $account->id,
        ]);
        $ingest = app(IngestTelegramUpdate::class);
        $post = $ingest->handle($this->payload($account, [
            'metrics' => ['views' => 100, 'forwards' => 5, 'reactions' => 12, 'comments' => 3],
        ]));

        $ingest->handle($this->payload($account, [
            'event_type' => 'metrics',
            'metrics' => ['views' => 250],
        ]));

        $this->assertNotNull($post);
        $post->refresh();
        $this->assertSame('Важная новость', $post->text);
        $this->assertSame(250, $post->views);
        $this->assertSame(5, $post->forwards);
        $this->assertSame(12, $post->reactions);
        $this->assertSame(3, $post->comments);
        $this->assertSame($channel->id, $post->source_channel_id);
    }

    public function test_source_statistics_only_aggregate_active_posts_from_last_day(): void
    {
        $channel = SourceChannel::factory()->create();
        SourcePost::factory()->create([
            'source_channel_id' => $channel->id,
            'posted_at' => now()->subHour(),
            'views' => 100,
            'forwards' => 4,
            'reactions' => 10,
            'comments' => 2,
        ]);
        SourcePost::factory()->create([
            'source_channel_id' => $channel->id,
            'posted_at' => now()->subHours(3),
            'views' => 250,
            'forwards' => 7,
            'reactions' => 20,
            'comments' => 5,
        ]);
        SourcePost::factory()->create([
            'source_channel_id' => $channel->id,
            'posted_at' => now()->subDays(2),
            'views' => 9999,
            'forwards' => 9999,
            'reactions' => 9999,
            'comments' => 9999,
        ]);

        $statistics = SourceChannel::query()->withLastDayStatistics()->findOrFail($channel->id);

        $this->assertSame(2, $statistics->posts_last_day_count);
        $this->assertSame(350, $statistics->views_last_day);
        $this->assertSame(11, $statistics->forwards_last_day);
        $this->assertSame(30, $statistics->reactions_last_day);
        $this->assertSame(7, $statistics->comments_last_day);
    }

    public function test_sync_command_queues_only_assigned_active_sources(): void
    {
        Queue::fake();
        $account = TelegramAccount::factory()->create();
        $assigned = SourceChannel::factory()->create(['collector_telegram_account_id' => $account->id]);
        SourceChannel::factory()->create();
        SourceChannel::factory()->create([
            'collector_telegram_account_id' => $account->id,
            'is_active' => false,
        ]);

        $this->artisan('telegram:sources:sync-statistics', ['--hours' => 48])->assertSuccessful();

        Queue::assertPushed(
            SyncSourceChannelStatisticsJob::class,
            fn (SyncSourceChannelStatisticsJob $job): bool => $job->sourceChannelId === $assigned->id
                && $job->lookbackHours === 48,
        );
        Queue::assertPushed(SyncSourceChannelStatisticsJob::class, 1);
    }

    public function test_history_is_fetched_and_ingested_by_the_madeline_owner_executor(): void
    {
        $account = TelegramAccount::factory()->create();
        $channel = SourceChannel::factory()->create([
            'telegram_peer_id' => -100123,
            'username' => null,
            'collector_telegram_account_id' => $account->id,
        ]);
        $client = Mockery::mock(MadelineClient::class);
        $client->shouldReceive('getHistory')
            ->once()
            ->with(-100123, 0, 100)
            ->andReturn([
                'messages' => [[
                    '_' => 'message',
                    'id' => 17,
                    'date' => now()->timestamp,
                    'message' => 'Историческая новость',
                    'views' => 120,
                    'forwards' => 4,
                ]],
            ]);
        $command = new TelegramOwnerCommand([
            'telegram_account_id' => $account->id,
            'type' => TelegramOwnerCommandType::SyncSourceHistory,
            'payload' => [
                'source_channel_id' => $channel->id,
                'lookback_hours' => 24,
            ],
        ]);

        $result = app(TelegramOwnerCommandExecutor::class)->execute($command, $client);

        $this->assertSame(['messages' => 1, 'lookback_hours' => 24], $result);
        $this->assertDatabaseHas('source_posts', [
            'source_channel_id' => $channel->id,
            'text' => 'Историческая новость',
        ]);
        $this->assertNotNull($channel->fresh()->last_backfilled_at);
    }

    public function test_resync_command_only_purges_selected_source_content_and_queues_requested_period(): void
    {
        Queue::fake();
        Storage::fake('local');
        $account = TelegramAccount::factory()->create();
        $group = SourceGroup::factory()->create();
        $channel = SourceChannel::factory()->create([
            'collector_telegram_account_id' => $account->id,
            'last_event_at' => now(),
            'last_backfilled_at' => now(),
            'metadata' => ['statistics_sync' => ['messages' => 10], 'keep' => true],
        ]);
        $group->sourceChannels()->attach($channel);
        $post = SourcePost::factory()->create(['source_channel_id' => $channel->id]);
        $message = SourceMessage::factory()->create([
            'source_post_id' => $post->id,
            'source_channel_id' => $channel->id,
        ]);
        $asset = MediaAsset::factory()->for($post, 'mediable')->create([
            'source_message_id' => $message->id,
            'ingest_key' => $message->id.':0',
            'path' => 'telegram/source.jpg',
            'preview_disk' => 'local',
            'preview_path' => 'telegram/source-preview.jpg',
        ]);
        Storage::disk('local')->put($asset->path, 'source');
        Storage::disk('local')->put($asset->preview_path, 'preview');
        $contentPlan = ContentPlan::factory()->create();
        $candidate = StoryCandidate::factory()->create(['content_plan_id' => $contentPlan->id]);
        $candidate->sourcePosts()->attach($post, ['is_primary' => true]);

        $this->artisan('telegram:sources:resync', [
            '--source' => $channel->id,
            '--hours' => 48,
            '--force' => true,
        ])->assertSuccessful();

        $this->assertModelExists($channel);
        $this->assertModelExists($group);
        $this->assertModelExists($account);
        $this->assertModelExists($candidate);
        $this->assertDatabaseHas('source_channel_source_group', [
            'source_channel_id' => $channel->id,
            'source_group_id' => $group->id,
        ]);
        $this->assertDatabaseMissing('source_posts', ['id' => $post->id]);
        $this->assertDatabaseMissing('source_messages', ['id' => $message->id]);
        $this->assertDatabaseMissing('media_assets', ['id' => $asset->id]);
        $this->assertDatabaseMissing('source_post_story_candidate', [
            'source_post_id' => $post->id,
            'story_candidate_id' => $candidate->id,
        ]);
        Storage::disk('local')->assertMissing('telegram/source.jpg');
        Storage::disk('local')->assertMissing('telegram/source-preview.jpg');
        $this->assertNull($channel->fresh()->last_backfilled_at);
        $this->assertTrue($channel->fresh()->metadata['keep']);
        $this->assertArrayNotHasKey('statistics_sync', $channel->fresh()->metadata);
        Queue::assertPushed(
            SyncSourceChannelStatisticsJob::class,
            fn (SyncSourceChannelStatisticsJob $job): bool => $job->sourceChannelId === $channel->id
                && $job->lookbackHours === 48,
        );
    }

    public function test_purge_keeps_a_media_file_that_is_still_used_by_a_planned_post(): void
    {
        Storage::fake('local');
        $channel = SourceChannel::factory()->create();
        $sourcePost = SourcePost::factory()->create(['source_channel_id' => $channel->id]);
        $sourceAsset = MediaAsset::factory()->for($sourcePost, 'mediable')->create([
            'path' => 'telegram/shared.jpg',
        ]);
        $contentPlan = ContentPlan::factory()->create();
        $candidate = StoryCandidate::factory()->create(['content_plan_id' => $contentPlan->id]);
        $plannedPost = PlannedPost::factory()->create([
            'content_plan_id' => $contentPlan->id,
            'story_candidate_id' => $candidate->id,
        ]);
        $plannedAsset = MediaAsset::factory()->for($plannedPost, 'mediable')->create([
            'origin_media_asset_id' => $sourceAsset->id,
            'path' => 'telegram/shared.jpg',
        ]);
        Storage::disk('local')->put('telegram/shared.jpg', 'shared');

        app(PurgeSourceChannelContent::class)->handle($channel);

        $this->assertModelExists($plannedAsset->fresh());
        $this->assertNull($plannedAsset->fresh()->origin_media_asset_id);
        Storage::disk('local')->assertExists('telegram/shared.jpg');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(TelegramAccount $account, array $overrides = []): array
    {
        return array_merge([
            'telegram_account_uuid' => $account->uuid,
            'event_type' => 'message',
            'peer_id' => -100123,
            'message_id' => 10,
            'posted_at' => now()->toIso8601String(),
            'text' => 'Важная новость',
            'metrics' => [],
            'media' => [],
        ], $overrides);
    }
}
