<?php

namespace Tests\Feature;

use App\Actions\ApprovePlannedPost;
use App\Actions\IngestTelegramUpdate;
use App\Contracts\MadelineClient;
use App\Jobs\DownloadMediaAssetJob;
use App\MediaType;
use App\Models\ContentPlan;
use App\Models\MediaAsset;
use App\Models\PlannedPost;
use App\Models\SourceChannel;
use App\Models\SourcePost;
use App\Models\StoryCandidate;
use App\Models\TelegramAccount;
use App\Models\User;
use App\PlannedPostStatus;
use App\Services\MadelineClientFactory;
use App\Services\MadelineClientPool;
use App\Services\PlannedPostMediaManager;
use App\Services\TelegramMessagePayloadFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class TelegramMediaWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_history_payload_extracts_photo_and_video_descriptors(): void
    {
        $account = TelegramAccount::factory()->create();
        $channel = SourceChannel::factory()->create();
        $factory = app(TelegramMessagePayloadFactory::class);
        $photoPayload = $factory->fromRawMessage($account, $channel, [
            'id' => 10,
            'date' => now()->timestamp,
            'message' => 'Фото',
            'media' => [
                '_' => 'messageMediaPhoto',
                'photo' => [
                    'id' => 100,
                    'sizes' => [
                        ['_' => 'photoSize', 'type' => 'm', 'size' => 120_000],
                    ],
                ],
            ],
        ]);
        $videoPayload = $factory->fromRawMessage($account, $channel, [
            'id' => 11,
            'date' => now()->timestamp,
            'message' => 'Видео',
            'media' => [
                '_' => 'messageMediaDocument',
                'document' => [
                    'id' => 200,
                    'mime_type' => 'video/mp4',
                    'size' => 5_000_000,
                    'attributes' => [
                        ['_' => 'documentAttributeVideo', 'duration' => 12],
                        ['_' => 'documentAttributeFilename', 'file_name' => 'news.mp4'],
                    ],
                    'thumbs' => [
                        ['_' => 'photoSize', 'type' => 'm', 'size' => 25_000],
                    ],
                ],
            ],
        ]);

        $this->assertSame('photo', $photoPayload['media'][0]['type']);
        $this->assertSame('photo:100', $photoPayload['media'][0]['external_id']);
        $this->assertSame('video', $videoPayload['media'][0]['type']);
        $this->assertSame('m', $videoPayload['media'][0]['metadata']['thumbnail_type']);
    }

    public function test_history_ingestion_stores_media_provenance_and_queues_photo_download(): void
    {
        Queue::fake();
        $account = TelegramAccount::factory()->create();
        $channel = SourceChannel::factory()->create([
            'telegram_peer_id' => -100123,
            'collector_telegram_account_id' => $account->id,
        ]);
        $payload = app(TelegramMessagePayloadFactory::class)->fromRawMessage($account, $channel, [
            '_' => 'message',
            'id' => 10,
            'date' => now()->timestamp,
            'message' => 'Фото',
            'media' => [
                '_' => 'messageMediaPhoto',
                'photo' => [
                    'id' => 100,
                    'sizes' => [['_' => 'photoSize', 'type' => 'm', 'size' => 120_000]],
                ],
            ],
        ]);

        $sourcePost = app(IngestTelegramUpdate::class)->handle($payload);
        $asset = $sourcePost?->mediaAssets()->firstOrFail();

        $this->assertNotNull($asset);
        $this->assertSame($sourcePost->messages()->firstOrFail()->id, $asset->source_message_id);
        $this->assertSame(MediaType::Photo, $asset->type);
        Queue::assertPushed(DownloadMediaAssetJob::class, fn (DownloadMediaAssetJob $job): bool => $job->mediaAssetId === $asset->id && ! $job->previewOnly);
    }

    public function test_live_and_history_payloads_update_the_same_media_slot(): void
    {
        Queue::fake();
        $account = TelegramAccount::factory()->create();
        $channel = SourceChannel::factory()->create([
            'telegram_peer_id' => -100123,
            'collector_telegram_account_id' => $account->id,
        ]);
        $ingest = app(IngestTelegramUpdate::class);
        $basePayload = [
            'telegram_account_uuid' => $account->uuid,
            'event_type' => 'message',
            'peer_id' => -100123,
            'message_id' => 10,
            'posted_at' => now()->toIso8601String(),
            'text' => 'Одна фотография',
            'metrics' => [],
        ];

        $post = $ingest->handle(array_merge($basePayload, [
            'media' => [[
                'type' => 'photo',
                'external_id' => 'AQAD-live-file-id',
                'disk' => 'local',
                'path' => 'telegram/live.jpg',
                'mime_type' => 'image/jpeg',
                'checksum' => str_repeat('a', 64),
                'metadata' => ['bot_api_file_id' => 'live-download-id'],
            ]],
        ]));
        $ingest->handle(array_merge($basePayload, [
            'media' => [[
                'type' => 'photo',
                'external_id' => 'photo:100',
                'mime_type' => 'image/jpeg',
                'metadata' => ['telegram_media_type' => 'photo'],
            ]],
        ]));

        $this->assertNotNull($post);
        $this->assertSame(1, $post->mediaAssets()->count());
        $asset = $post->mediaAssets()->firstOrFail();
        $this->assertSame($post->messages()->firstOrFail()->id.':0', $asset->ingest_key);
        $this->assertSame('photo:100', $asset->external_id);
    }

    public function test_repeated_payload_preserves_an_already_downloaded_media_file(): void
    {
        Queue::fake();
        $account = TelegramAccount::factory()->create();
        $channel = SourceChannel::factory()->create([
            'telegram_peer_id' => -100123,
            'collector_telegram_account_id' => $account->id,
        ]);
        $payload = [
            'telegram_account_uuid' => $account->uuid,
            'event_type' => 'message',
            'peer_id' => -100123,
            'message_id' => 10,
            'posted_at' => now()->toIso8601String(),
            'text' => 'Одна фотография',
            'metrics' => [],
            'media' => [[
                'type' => 'photo',
                'external_id' => 'photo:100',
                'disk' => 'local',
                'path' => 'telegram/downloaded.jpg',
                'mime_type' => 'image/jpeg',
                'checksum' => str_repeat('b', 64),
            ]],
        ];
        $ingest = app(IngestTelegramUpdate::class);
        $post = $ingest->handle($payload);
        $payload['media'][0]['path'] = null;
        $payload['media'][0]['checksum'] = null;

        $ingest->handle($payload);

        $asset = $post?->mediaAssets()->firstOrFail();
        $this->assertNotNull($asset);
        $this->assertSame('telegram/downloaded.jpg', $asset->path);
        $this->assertSame(str_repeat('b', 64), $asset->checksum);
        Queue::assertNotPushed(DownloadMediaAssetJob::class);
    }

    public function test_editor_can_mix_and_order_media_from_multiple_sources(): void
    {
        Queue::fake();
        $plan = ContentPlan::factory()->create();
        $candidate = StoryCandidate::factory()->create(['content_plan_id' => $plan->id]);
        $firstSource = SourcePost::factory()->create();
        $secondSource = SourcePost::factory()->create();
        $candidate->sourcePosts()->attach($firstSource, ['is_primary' => true]);
        $candidate->sourcePosts()->attach($secondSource, ['is_primary' => false]);
        $firstAsset = MediaAsset::factory()->for($firstSource, 'mediable')->create(['sort_order' => 0]);
        $secondAsset = MediaAsset::factory()->for($secondSource, 'mediable')->create(['sort_order' => 0]);
        $post = PlannedPost::factory()->create([
            'content_plan_id' => $plan->id,
            'story_candidate_id' => $candidate->id,
        ]);

        app(PlannedPostMediaManager::class)->replaceSelection($post, [$secondAsset->id, $firstAsset->id]);

        $selected = $post->mediaAssets()->orderBy('sort_order')->get();
        $this->assertSame([$secondAsset->id, $firstAsset->id], $selected->pluck('origin_media_asset_id')->all());
        $this->assertSame([0, 1], $selected->pluck('sort_order')->all());
    }

    public function test_post_cannot_be_approved_while_selected_media_is_not_ready(): void
    {
        $user = User::factory()->create();
        $plan = ContentPlan::factory()->create();
        $candidate = StoryCandidate::factory()->create(['content_plan_id' => $plan->id]);
        $post = PlannedPost::factory()->create([
            'content_plan_id' => $plan->id,
            'story_candidate_id' => $candidate->id,
            'status' => PlannedPostStatus::FinalReview,
            'risk_flags' => [],
        ]);
        MediaAsset::factory()->for($post, 'mediable')->create([
            'path' => null,
            'downloaded_at' => null,
        ]);

        $this->expectException(ValidationException::class);

        app(ApprovePlannedPost::class)->approve($post, $user);
    }

    public function test_download_jobs_reuse_one_pooled_client_for_the_same_account(): void
    {
        Storage::fake('local');
        $account = TelegramAccount::factory()->create();
        $channel = SourceChannel::factory()->create([
            'collector_telegram_account_id' => $account->id,
        ]);
        $firstPost = SourcePost::factory()->create(['source_channel_id' => $channel->id]);
        $secondPost = SourcePost::factory()->create(['source_channel_id' => $channel->id]);
        $firstMessage = $firstPost->messages()->create([
            'source_channel_id' => $channel->id,
            'external_message_id' => 100,
            'text' => 'Первое фото',
            'entities' => [],
            'metrics' => [],
            'raw_payload' => [],
            'posted_at' => now(),
        ]);
        $secondMessage = $secondPost->messages()->create([
            'source_channel_id' => $channel->id,
            'external_message_id' => 101,
            'text' => 'Второе фото',
            'entities' => [],
            'metrics' => [],
            'raw_payload' => [],
            'posted_at' => now(),
        ]);
        $firstAsset = MediaAsset::factory()->for($firstPost, 'mediable')->create([
            'source_message_id' => $firstMessage->id,
            'path' => null,
            'checksum' => null,
            'downloaded_at' => null,
            'metadata' => ['bot_api_file_id' => 'first-file'],
        ]);
        $secondAsset = MediaAsset::factory()->for($secondPost, 'mediable')->create([
            'source_message_id' => $secondMessage->id,
            'path' => null,
            'checksum' => null,
            'downloaded_at' => null,
            'metadata' => ['bot_api_file_id' => 'second-file'],
        ]);
        $client = Mockery::mock(MadelineClient::class);
        $client->shouldReceive('downloadToFile')
            ->twice()
            ->andReturnUsing(function (mixed $media, string $path): string {
                File::put($path, 'downloaded');

                return $path;
            });
        $factory = Mockery::mock(MadelineClientFactory::class);
        $factory->shouldReceive('make')->once()->andReturn($client);
        $pool = new MadelineClientPool($factory);

        (new DownloadMediaAssetJob($firstAsset->id))->handle($pool);
        (new DownloadMediaAssetJob($secondAsset->id))->handle($pool);

        $this->assertNotNull($firstAsset->fresh()->path);
        $this->assertNotNull($secondAsset->fresh()->path);
        Storage::disk('local')->assertExists($firstAsset->fresh()->path);
        Storage::disk('local')->assertExists($secondAsset->fresh()->path);
    }
}
