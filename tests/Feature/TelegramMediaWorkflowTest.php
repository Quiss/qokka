<?php

namespace Tests\Feature;

use App\Actions\ApprovePlannedPost;
use App\Actions\IngestTelegramUpdate;
use App\Actions\RequestTelegramMediaDownload;
use App\Contracts\MadelineClient;
use App\Contracts\TelegramMediaClient;
use App\Exceptions\PermanentTelegramMediaException;
use App\Jobs\DownloadMediaAssetJob;
use App\MediaType;
use App\Models\ContentPlan;
use App\Models\MediaAsset;
use App\Models\PlannedPost;
use App\Models\Source;
use App\Models\SourcePost;
use App\Models\StoryCandidate;
use App\Models\TelegramAccount;
use App\Models\TelegramOwnerCommand;
use App\Models\User;
use App\PlannedPostStatus;
use App\Services\PlannedPostMediaManager;
use App\Services\TelegramMessagePayloadFactory;
use App\Services\TelegramOwnerMediaDownloader;
use App\TelegramAccountStatus;
use App\TelegramOwnerCommandStatus;
use App\TelegramOwnerCommandType;
use App\TelegramSourceAccessStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Mockery;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class TelegramMediaWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_history_payload_extracts_photo_and_video_descriptors(): void
    {
        $account = TelegramAccount::factory()->create();
        $channel = Source::factory()->create();
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
                        ['_' => 'photoSize', 'type' => 'm', 'w' => 800, 'h' => 600, 'size' => 197_205],
                        [
                            '_' => 'photoSizeProgressive',
                            'type' => 'x',
                            'w' => 1600,
                            'h' => 1200,
                            'sizes' => [42_000, 197_205, 332_141],
                        ],
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
                        ['_' => 'documentAttributeVideo', 'duration' => 12, 'w' => 1080, 'h' => 1920, 'supports_streaming' => true],
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
        $this->assertSame(332_141, $photoPayload['media'][0]['size_bytes']);
        $this->assertSame('video', $videoPayload['media'][0]['type']);
        $this->assertSame('m', $videoPayload['media'][0]['metadata']['thumbnail_type']);
        $this->assertSame(1080, $videoPayload['media'][0]['metadata']['width']);
        $this->assertSame(1920, $videoPayload['media'][0]['metadata']['height']);
        $this->assertSame(12, $videoPayload['media'][0]['metadata']['duration']);
        $this->assertTrue($videoPayload['media'][0]['metadata']['supports_streaming']);
    }

    public function test_history_ingestion_stores_media_provenance_and_queues_photo_download(): void
    {
        Queue::fake();
        $account = TelegramAccount::factory()->create();
        $channel = Source::factory()->create([
            'telegram_peer_id' => -100123,
            'username' => null,
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
        $sourceMessage = $sourcePost->messages()->firstOrFail();
        $this->assertSame($sourceMessage->id, $asset->source_message_id);
        $this->assertSame($account->id, $sourceMessage->telegram_account_id);
        $this->assertSame(MediaType::Photo, $asset->type);
        $this->assertOwnerMediaCommand($asset, TelegramOwnerCommandType::DownloadMedia);
    }

    public function test_history_ingestion_queues_video_preview_as_a_background_download(): void
    {
        Queue::fake();
        $account = TelegramAccount::factory()->create();
        $channel = Source::factory()->create([
            'telegram_peer_id' => -100123,
            'username' => null,
            'collector_telegram_account_id' => $account->id,
        ]);
        $payload = app(TelegramMessagePayloadFactory::class)->fromRawMessage($account, $channel, [
            '_' => 'message',
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
                        ['_' => 'documentAttributeVideo', 'duration' => 12, 'w' => 1080, 'h' => 1920],
                    ],
                    'thumbs' => [
                        ['_' => 'photoSize', 'type' => 'm', 'size' => 25_000],
                    ],
                ],
            ],
        ]);

        $sourcePost = app(IngestTelegramUpdate::class)->handle($payload);
        $asset = $sourcePost?->mediaAssets()->firstOrFail();

        $this->assertNotNull($asset);
        $this->assertSame(MediaType::Video, $asset->type);
        $this->assertOwnerMediaCommand($asset, TelegramOwnerCommandType::DownloadMediaPreview);
    }

    public function test_history_ingestion_queues_animation_as_a_full_download(): void
    {
        Queue::fake();
        $account = TelegramAccount::factory()->create();
        $channel = Source::factory()->create([
            'telegram_peer_id' => -100123,
            'username' => null,
            'collector_telegram_account_id' => $account->id,
        ]);
        $payload = app(TelegramMessagePayloadFactory::class)->fromRawMessage($account, $channel, [
            '_' => 'message',
            'id' => 12,
            'date' => now()->timestamp,
            'message' => 'Анимация',
            'media' => [
                '_' => 'messageMediaDocument',
                'document' => [
                    'id' => 201,
                    'mime_type' => 'video/mp4',
                    'size' => 1_000_000,
                    'attributes' => [
                        ['_' => 'documentAttributeAnimated'],
                        ['_' => 'documentAttributeFilename', 'file_name' => 'animation.mp4'],
                    ],
                ],
            ],
        ]);

        $sourcePost = app(IngestTelegramUpdate::class)->handle($payload);
        $asset = $sourcePost?->mediaAssets()->firstOrFail();

        $this->assertSame(MediaType::Animation, $asset->type);
        $this->assertOwnerMediaCommand($asset, TelegramOwnerCommandType::DownloadMedia);
    }

    public function test_media_download_fails_immediately_when_the_source_has_no_collector(): void
    {
        Queue::fake();
        $account = TelegramAccount::factory()->create();
        $channel = Source::factory()->create([
            'telegram_peer_id' => -100123,
            'username' => null,
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
        $channel->update(['collector_telegram_account_id' => null]);
        TelegramOwnerCommand::query()->delete();

        try {
            app(RequestTelegramMediaDownload::class)->handle($asset);
            $this->fail('Media download did not fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('не найден Telegram-аккаунт', $exception->getMessage());
        }

        $this->assertNull($asset->fresh()->failed_at);
        $this->assertDatabaseCount('telegram_owner_commands', 0);
    }

    public function test_media_download_falls_back_to_the_message_account_and_repairs_the_collector(): void
    {
        Storage::fake('local');
        $staleCollector = TelegramAccount::factory()->create([
            'status' => TelegramAccountStatus::Error,
            'last_seen_at' => now()->subMinutes(10),
        ]);
        $messageAccount = TelegramAccount::factory()->create();
        $channel = Source::factory()->create([
            'telegram_peer_id' => -100123,
            'username' => null,
            'collector_telegram_account_id' => $staleCollector->id,
        ]);
        $channel->telegramAccounts()->sync([
            $staleCollector->id => ['access_status' => TelegramSourceAccessStatus::Available->value],
            $messageAccount->id => ['access_status' => TelegramSourceAccessStatus::Available->value],
        ]);
        $sourcePost = SourcePost::factory()->create(['source_id' => $channel->id]);
        $message = $sourcePost->messages()->create([
            'source_id' => $channel->id,
            'telegram_account_id' => $messageAccount->id,
            'external_message_id' => 100,
            'text' => 'Фото',
            'entities' => [],
            'metrics' => [],
            'raw_payload' => [],
            'posted_at' => now(),
        ]);
        $asset = MediaAsset::factory()->for($sourcePost, 'mediable')->create([
            'source_message_id' => $message->id,
            'type' => MediaType::Photo,
            'path' => null,
            'downloaded_at' => null,
            'mime_type' => 'image/jpeg',
        ]);
        $client = Mockery::mock(MadelineClient::class);
        $client->shouldReceive('getChannelMessage')->once()->andReturn([
            '_' => 'message',
            'id' => 100,
            'media' => ['_' => 'messageMediaPhoto'],
        ]);
        $client->shouldReceive('downloadToFile')
            ->once()
            ->andReturnUsing(function (mixed $media, string $path): string {
                File::put($path, 'downloaded');

                return $path;
            });
        $command = app(RequestTelegramMediaDownload::class)->handle($asset);
        $this->runOwnerDownload($asset, $client);

        $this->assertSame($messageAccount->id, $command->telegram_account_id);
        $this->assertSame($messageAccount->id, $channel->fresh()->collector_telegram_account_id);
        $this->assertNotNull($asset->fresh()->path);
    }

    public function test_duplicate_media_requests_reuse_the_same_owner_command(): void
    {
        Queue::fake();
        $account = TelegramAccount::factory()->create();
        $channel = Source::factory()->create([
            'collector_telegram_account_id' => $account->id,
        ]);
        $channel->telegramAccounts()->attach($account, [
            'access_status' => TelegramSourceAccessStatus::Available->value,
        ]);
        $message = SourcePost::factory()
            ->create(['source_id' => $channel->id])
            ->messages()
            ->create([
                'source_id' => $channel->id,
                'telegram_account_id' => $account->id,
                'external_message_id' => 100,
                'entities' => [],
                'metrics' => [],
                'raw_payload' => [],
                'posted_at' => now(),
            ]);
        $asset = MediaAsset::factory()->for($message->sourcePost, 'mediable')->create([
            'source_message_id' => $message->id,
            'path' => null,
            'downloaded_at' => null,
        ]);
        $first = app(RequestTelegramMediaDownload::class)->handle($asset);
        $first->update(['max_attempts' => 1]);
        $second = app(RequestTelegramMediaDownload::class)->handle($asset);

        $this->assertTrue($first->is($second));
        $this->assertDatabaseCount('telegram_owner_commands', 1);
        $this->assertSame(3, $second->max_attempts);
    }

    public function test_missing_media_backlog_does_not_retry_terminal_failures_implicitly(): void
    {
        $account = TelegramAccount::factory()->create();
        $channel = Source::factory()->create([
            'collector_telegram_account_id' => $account->id,
        ]);
        $sourcePost = SourcePost::factory()->create(['source_id' => $channel->id]);
        $message = $sourcePost->messages()->create([
            'source_id' => $channel->id,
            'telegram_account_id' => $account->id,
            'external_message_id' => 100,
            'entities' => [],
            'metrics' => [],
            'raw_payload' => [],
            'posted_at' => now(),
        ]);
        $asset = MediaAsset::factory()->for($sourcePost, 'mediable')->create([
            'source_message_id' => $message->id,
            'path' => null,
            'downloaded_at' => null,
            'failed_at' => now(),
            'metadata' => ['download_error' => 'Terminal Telegram failure.'],
        ]);

        $this->artisan('telegram:media:request-missing')->assertSuccessful();
        $this->assertDatabaseCount('telegram_owner_commands', 0);

        $this->artisan('telegram:media:request-missing', [
            '--include-failed' => true,
        ])->assertSuccessful();

        $this->assertOwnerMediaCommand($asset, TelegramOwnerCommandType::DownloadMedia);
        $this->assertNull($asset->fresh()->failed_at);
    }

    public function test_missing_source_message_is_a_permanent_failure(): void
    {
        $asset = MediaAsset::factory()->create([
            'source_message_id' => null,
            'path' => null,
            'downloaded_at' => null,
        ]);
        $client = Mockery::mock(MadelineClient::class);
        $client->shouldNotReceive('getChannelMessage');
        $client->shouldNotReceive('downloadToFile');

        $this->expectException(PermanentTelegramMediaException::class);

        $this->runOwnerDownload($asset, $client);
    }

    public function test_live_and_history_payloads_update_the_same_media_slot(): void
    {
        Queue::fake();
        $account = TelegramAccount::factory()->create();
        $channel = Source::factory()->create([
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
        $channel = Source::factory()->create([
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
        $this->assertDatabaseCount('telegram_owner_commands', 0);
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
        $firstAsset = MediaAsset::factory()->for($firstSource, 'mediable')->create([
            'path' => null,
            'downloaded_at' => null,
            'sort_order' => 0,
        ]);
        $secondAsset = MediaAsset::factory()->for($secondSource, 'mediable')->create([
            'path' => null,
            'downloaded_at' => null,
            'sort_order' => 0,
        ]);
        $post = PlannedPost::factory()->create([
            'content_plan_id' => $plan->id,
            'story_candidate_id' => $candidate->id,
        ]);

        app(PlannedPostMediaManager::class)->replaceSelection($post, [$secondAsset->id, $firstAsset->id]);

        $selected = $post->mediaAssets()->orderBy('sort_order')->get();
        $this->assertSame([$secondAsset->id, $firstAsset->id], $selected->pluck('origin_media_asset_id')->all());
        $this->assertSame([0, 1], $selected->pluck('sort_order')->all());
        Queue::assertNotPushed(DownloadMediaAssetJob::class);
    }

    public function test_animation_cannot_be_mixed_with_other_selected_media(): void
    {
        $plan = ContentPlan::factory()->create();
        $candidate = StoryCandidate::factory()->create(['content_plan_id' => $plan->id]);
        $sourcePost = SourcePost::factory()->create();
        $candidate->sourcePosts()->attach($sourcePost, ['is_primary' => true]);
        $photo = MediaAsset::factory()->for($sourcePost, 'mediable')->create();
        $animation = MediaAsset::factory()->for($sourcePost, 'mediable')->create([
            'type' => MediaType::Animation,
        ]);
        $post = PlannedPost::factory()->create([
            'content_plan_id' => $plan->id,
            'story_candidate_id' => $candidate->id,
        ]);

        try {
            app(PlannedPostMediaManager::class)->replaceSelection($post, [$photo->id, $animation->id]);
            $this->fail('An animation mixed with other media should fail.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['GIF-анимацию можно публиковать только отдельно от других медиа.'],
                $exception->errors()['media_asset_ids'],
            );
        }

        $this->assertDatabaseCount('media_assets', 2);
    }

    public function test_oversized_media_error_shows_file_size_and_configured_limit(): void
    {
        config(['services.telegram.media_max_bytes' => 300 * 1024 * 1024]);

        $plan = ContentPlan::factory()->create();
        $candidate = StoryCandidate::factory()->create(['content_plan_id' => $plan->id]);
        $sourcePost = SourcePost::factory()->create();
        $candidate->sourcePosts()->attach($sourcePost, ['is_primary' => true]);
        $asset = MediaAsset::factory()->for($sourcePost, 'mediable')->create([
            'type' => MediaType::Video,
            'size_bytes' => 367_525_888,
        ]);
        $post = PlannedPost::factory()->create([
            'content_plan_id' => $plan->id,
            'story_candidate_id' => $candidate->id,
        ]);

        try {
            app(PlannedPostMediaManager::class)->replaceSelection($post, [$asset->id]);
            $this->fail('Oversized media selection should fail.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['Файл размером 350.5 MB превышает лимит 300 MB и не может быть выбран.'],
                $exception->errors()['media_asset_ids'],
            );
        }
    }

    public function test_editor_can_upload_and_order_custom_media_with_source_media(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $plan = ContentPlan::factory()->create();
        $candidate = StoryCandidate::factory()->create(['content_plan_id' => $plan->id]);
        $sourcePost = SourcePost::factory()->create();
        $candidate->sourcePosts()->attach($sourcePost, ['is_primary' => true]);
        $sourceAsset = MediaAsset::factory()->for($sourcePost, 'mediable')->create([
            'path' => 'telegram/source.jpg',
        ]);
        Storage::disk('local')->put('telegram/source.jpg', 'source-photo');
        $post = PlannedPost::factory()->create([
            'content_plan_id' => $plan->id,
            'story_candidate_id' => $candidate->id,
        ]);
        $upload = UploadedFile::fake()->image('replacement.jpg')->size(200);

        app(PlannedPostMediaManager::class)->replaceEditorSelection(
            $post,
            ['upload:'.$upload->getFilename(), 'source:'.$sourceAsset->id],
            [$upload],
            $user->id,
        );

        $selected = $post->mediaAssets()->get();
        $customAsset = $selected->first();
        $this->assertCount(2, $selected);
        $this->assertNull($customAsset?->origin_media_asset_id);
        $this->assertSame(MediaType::Photo, $customAsset?->type);
        $this->assertSame('replacement.jpg', data_get($customAsset?->metadata, 'original_name'));
        $this->assertSame($user->id, data_get($customAsset?->metadata, 'uploaded_by_id'));
        $this->assertSame($sourceAsset->id, $selected->last()?->origin_media_asset_id);
        Storage::disk('local')->assertExists((string) $customAsset?->path);
    }

    public function test_editor_reads_temporary_upload_metadata_before_moving_it(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $post = PlannedPost::factory()->create();
        $temporaryFilename = 'temporary-editor-upload.jpg';
        $temporaryPath = 'livewire-tmp/'.$temporaryFilename;

        Storage::disk('local')->put($temporaryPath, 'photo-content');
        Storage::disk('local')->put($temporaryPath.'.json', json_encode([
            'name' => 'original-photo.jpg',
            'type' => 'image/jpeg',
            'size' => 13,
            'hash' => 'stored-editor-upload.jpg',
        ], JSON_THROW_ON_ERROR));

        $upload = new class($temporaryFilename, 'local') extends TemporaryUploadedFile
        {
            public function getMimeType(): string
            {
                if (! $this->exists()) {
                    throw new RuntimeException('MIME type was read after the temporary upload was moved.');
                }

                return parent::getMimeType();
            }

            public function getSize(): int
            {
                if (! $this->exists()) {
                    throw new RuntimeException('Size was read after the temporary upload was moved.');
                }

                return parent::getSize();
            }
        };

        $temporaryAssets = app(PlannedPostMediaManager::class)->temporaryUploadAssets([$upload]);

        $this->assertSame('Своё медиа', $temporaryAssets[0]['source_label']);
        $this->assertSame('Фото', $temporaryAssets[0]['type_label']);
        $this->assertSame('0,0 МБ', $temporaryAssets[0]['size_label']);
        $this->assertStringContainsString('/preview-file/', $temporaryAssets[0]['preview_url']);

        app(PlannedPostMediaManager::class)->replaceEditorSelection(
            $post,
            ['upload:'.$upload->getFilename()],
            [$upload],
            $user->id,
        );

        $customAsset = $post->mediaAssets()->sole();

        $this->assertSame('image/jpeg', $customAsset->mime_type);
        $this->assertSame(13, $customAsset->size_bytes);
        $this->assertSame('original-photo.jpg', data_get($customAsset->metadata, 'original_name'));
        Storage::disk('local')->assertMissing($temporaryPath);
        Storage::disk('local')->assertExists((string) $customAsset->path);
    }

    public function test_editor_upload_rejects_an_unsupported_file_type(): void
    {
        Storage::fake('local');
        $post = PlannedPost::factory()->create();
        $upload = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

        try {
            app(PlannedPostMediaManager::class)->replaceEditorSelection(
                $post,
                ['upload:'.$upload->getFilename()],
                [$upload],
            );
            $this->fail('An unsupported upload should fail.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['Разрешены JPEG, PNG, WebP, GIF и MP4.'],
                $exception->errors()['custom_media_uploads'],
            );
        }

        $this->assertSame(0, $post->mediaAssets()->count());
    }

    public function test_editor_upload_rejects_a_file_over_the_configured_limit(): void
    {
        Storage::fake('local');
        config(['services.telegram.media_max_bytes' => 100 * 1024]);
        $post = PlannedPost::factory()->create();
        $upload = UploadedFile::fake()->image('too-large.jpg')->size(101);

        try {
            app(PlannedPostMediaManager::class)->replaceEditorSelection(
                $post,
                ['upload:'.$upload->getFilename()],
                [$upload],
            );
            $this->fail('An oversized upload should fail.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['Файл размером 101 KB превышает лимит 100 KB.'],
                $exception->errors()['custom_media_uploads'],
            );
        }

        $this->assertSame(0, $post->mediaAssets()->count());
    }

    public function test_unknown_size_source_media_cannot_be_selected(): void
    {
        $plan = ContentPlan::factory()->create();
        $candidate = StoryCandidate::factory()->create(['content_plan_id' => $plan->id]);
        $sourcePost = SourcePost::factory()->create();
        $candidate->sourcePosts()->attach($sourcePost, ['is_primary' => true]);
        $asset = MediaAsset::factory()->for($sourcePost, 'mediable')->create(['size_bytes' => null]);
        $post = PlannedPost::factory()->create([
            'content_plan_id' => $plan->id,
            'story_candidate_id' => $candidate->id,
        ]);

        try {
            app(PlannedPostMediaManager::class)->replaceSelection($post, [$asset->id]);
            $this->fail('Media with an unknown size should fail.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString(
                'Размер файла неизвестен',
                $exception->errors()['media_asset_ids'][0],
            );
        }
    }

    public function test_post_cannot_be_approved_while_selected_media_is_not_ready(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $plan = ContentPlan::factory()->create();
        $candidate = StoryCandidate::factory()->create(['content_plan_id' => $plan->id]);
        $post = PlannedPost::factory()->create([
            'content_plan_id' => $plan->id,
            'story_candidate_id' => $candidate->id,
            'status' => PlannedPostStatus::FinalReview,
            'risk_flags' => [],
        ]);
        $mediaAsset = MediaAsset::factory()->for($post, 'mediable')->create([
            'path' => null,
            'downloaded_at' => null,
        ]);

        try {
            app(ApprovePlannedPost::class)->approve($post, $user);
            $this->fail('Approval should fail while selected media is not ready.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['Выбранное медиа загружается из Telegram. Дождитесь завершения и повторите одобрение.'],
                $exception->errors()['media'],
            );
            Queue::assertNotPushed(DownloadMediaAssetJob::class);
        }
    }

    public function test_owner_downloads_reuse_the_same_live_madeline_client(): void
    {
        Storage::fake('local');
        $account = TelegramAccount::factory()->create();
        $channel = Source::factory()->create([
            'collector_telegram_account_id' => $account->id,
        ]);
        $firstPost = SourcePost::factory()->create(['source_id' => $channel->id]);
        $secondPost = SourcePost::factory()->create(['source_id' => $channel->id]);
        $firstMessage = $firstPost->messages()->create([
            'source_id' => $channel->id,
            'external_message_id' => 100,
            'text' => 'Первое фото',
            'entities' => [],
            'metrics' => [],
            'raw_payload' => [],
            'posted_at' => now(),
        ]);
        $secondMessage = $secondPost->messages()->create([
            'source_id' => $channel->id,
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
            'size_bytes' => strlen('downloaded'),
            'metadata' => ['bot_api_file_id' => 'first-file'],
        ]);
        $secondAsset = MediaAsset::factory()->for($secondPost, 'mediable')->create([
            'source_message_id' => $secondMessage->id,
            'path' => null,
            'checksum' => null,
            'downloaded_at' => null,
            'size_bytes' => strlen('downloaded'),
            'metadata' => ['bot_api_file_id' => 'second-file'],
        ]);
        $downloadedMessageIds = [];
        $client = Mockery::mock(MadelineClient::class);
        $client->shouldReceive('getChannelMessage')
            ->twice()
            ->andReturnUsing(fn (int|string $peer, int $messageId): array => [
                '_' => 'message',
                'id' => $messageId,
                'media' => ['_' => 'messageMediaDocument'],
            ]);
        $client->shouldReceive('downloadToFile')
            ->twice()
            ->andReturnUsing(function (mixed $media, string $path) use (&$downloadedMessageIds): string {
                $downloadedMessageIds[] = $media['id'];
                File::put($path, 'downloaded');

                return $path;
            });
        $this->runOwnerDownload($firstAsset, $client);
        $this->runOwnerDownload($secondAsset, $client);

        $this->assertNotNull($firstAsset->fresh()->path);
        $this->assertNotNull($secondAsset->fresh()->path);
        Storage::disk('local')->assertExists($firstAsset->fresh()->path);
        Storage::disk('local')->assertExists($secondAsset->fresh()->path);
        $this->assertSame([100, 101], $downloadedMessageIds);
    }

    public function test_owner_download_resolves_a_fresh_peer_database_through_the_source_username(): void
    {
        Storage::fake('local');
        $account = TelegramAccount::factory()->create();
        $channel = Source::factory()->create([
            'telegram_peer_id' => -1_002_586_170_533,
            'username' => 'public_source',
            'collector_telegram_account_id' => $account->id,
        ]);
        $sourcePost = SourcePost::factory()->create(['source_id' => $channel->id]);
        $message = $sourcePost->messages()->create([
            'source_id' => $channel->id,
            'external_message_id' => 5721,
            'text' => 'Фото',
            'entities' => [],
            'metrics' => [],
            'raw_payload' => [],
            'posted_at' => now(),
        ]);
        $asset = MediaAsset::factory()->for($sourcePost, 'mediable')->create([
            'source_message_id' => $message->id,
            'type' => MediaType::Photo,
            'path' => null,
            'checksum' => null,
            'downloaded_at' => null,
            'size_bytes' => strlen('downloaded'),
            'mime_type' => 'image/jpeg',
        ]);
        $client = Mockery::mock(MadelineClient::class.', '.TelegramMediaClient::class);
        $client->shouldReceive('getChannelMessage')
            ->once()
            ->with('@public_source', 5721)
            ->andReturn([
                '_' => 'message',
                'id' => 5721,
                'media' => ['_' => 'messageMediaPhoto'],
            ]);
        $client->shouldReceive('downloadMessageToFile')
            ->once()
            ->with('@public_source', 5721, Mockery::type('string'), false)
            ->andReturnUsing(function (
                int|string $peer,
                int $messageId,
                string $path,
            ): string {
                File::put($path, 'downloaded');

                return $path;
            });
        $client->shouldNotReceive('downloadToFile');

        $this->runOwnerDownload($asset, $client);

        $this->assertNotNull($asset->fresh()->path);
        Storage::disk('local')->assertExists($asset->fresh()->path);
    }

    public function test_owner_download_detaches_unavailable_media_and_unprepared_editor_selections(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('telegram/previews/unavailable.jpg', 'preview');
        $account = TelegramAccount::factory()->create();
        $channel = Source::factory()->create([
            'telegram_peer_id' => -100123,
            'username' => null,
            'collector_telegram_account_id' => $account->id,
        ]);
        $sourcePost = SourcePost::factory()->create(['source_id' => $channel->id]);
        $message = $sourcePost->messages()->create([
            'source_id' => $channel->id,
            'external_message_id' => 100,
            'text' => 'Удалённое видео',
            'entities' => [],
            'metrics' => [],
            'raw_payload' => [],
            'posted_at' => now(),
        ]);
        $origin = MediaAsset::factory()->for($sourcePost, 'mediable')->create([
            'source_message_id' => $message->id,
            'type' => MediaType::Video,
            'path' => null,
            'preview_path' => 'telegram/previews/unavailable.jpg',
            'preview_disk' => 'local',
            'downloaded_at' => null,
            'mime_type' => 'video/mp4',
        ]);
        $firstPlannedPost = PlannedPost::factory()->create();
        $secondPlannedPost = PlannedPost::factory()->create();
        $firstSelection = MediaAsset::factory()->for($firstPlannedPost, 'mediable')->create([
            'source_message_id' => $message->id,
            'origin_media_asset_id' => $origin->id,
            'type' => MediaType::Video,
            'path' => null,
            'preview_path' => 'telegram/previews/unavailable.jpg',
            'preview_disk' => 'local',
            'downloaded_at' => null,
            'mime_type' => 'video/mp4',
        ]);
        $secondSelection = MediaAsset::factory()->for($secondPlannedPost, 'mediable')->create([
            'source_message_id' => $message->id,
            'origin_media_asset_id' => $origin->id,
            'type' => MediaType::Video,
            'path' => null,
            'preview_path' => 'telegram/previews/unavailable.jpg',
            'preview_disk' => 'local',
            'downloaded_at' => null,
            'mime_type' => 'video/mp4',
        ]);
        $client = Mockery::mock(MadelineClient::class);
        $client->shouldReceive('getChannelMessage')
            ->once()
            ->with(-100123, 100)
            ->andReturnNull();
        $client->shouldNotReceive('downloadToFile');
        $result = $this->runOwnerDownload($origin, $client);

        $this->assertTrue($result['detached']);
        $this->assertSame(2, $result['removed_planned_selections']);
        $this->assertSame(0, $result['preserved_planned_selections']);
        $this->assertModelMissing($origin);
        $this->assertModelMissing($firstSelection);
        $this->assertModelMissing($secondSelection);
        $this->assertSame(0, $sourcePost->mediaAssets()->count());
        $this->assertSame(0, $firstPlannedPost->mediaAssets()->count());
        $this->assertSame(0, $secondPlannedPost->mediaAssets()->count());
        Storage::disk('local')->assertMissing('telegram/previews/unavailable.jpg');
    }

    public function test_empty_telegram_media_keeps_an_already_downloaded_editor_video(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('telegram/media/video.mp4', 'video');
        $account = TelegramAccount::factory()->create();
        $channel = Source::factory()->create([
            'telegram_peer_id' => -100123,
            'username' => null,
            'collector_telegram_account_id' => $account->id,
        ]);
        $sourcePost = SourcePost::factory()->create(['source_id' => $channel->id]);
        $message = $sourcePost->messages()->create([
            'source_id' => $channel->id,
            'external_message_id' => 100,
            'text' => 'Скачанное видео',
            'entities' => [],
            'metrics' => [],
            'raw_payload' => [],
            'posted_at' => now(),
        ]);
        $origin = MediaAsset::factory()->for($sourcePost, 'mediable')->create([
            'source_message_id' => $message->id,
            'type' => MediaType::Video,
            'path' => 'telegram/media/video.mp4',
            'preview_path' => null,
            'preview_downloaded_at' => null,
            'mime_type' => 'video/mp4',
        ]);
        $plannedPost = PlannedPost::factory()->create();
        $selection = MediaAsset::factory()->for($plannedPost, 'mediable')->create([
            'source_message_id' => $message->id,
            'origin_media_asset_id' => $origin->id,
            'type' => MediaType::Video,
            'path' => 'telegram/media/video.mp4',
            'preview_path' => null,
            'preview_downloaded_at' => null,
            'mime_type' => 'video/mp4',
        ]);
        $client = Mockery::mock(MadelineClient::class);
        $client->shouldReceive('getChannelMessage')
            ->once()
            ->with(-100123, 100)
            ->andReturn([
                '_' => 'message',
                'id' => 100,
                'media' => ['_' => 'messageMediaEmpty'],
            ]);
        $client->shouldNotReceive('downloadToFile');

        $result = $this->runOwnerDownload($origin, $client, previewOnly: true);

        $this->assertTrue($result['detached']);
        $this->assertSame(0, $result['removed_planned_selections']);
        $this->assertSame(1, $result['preserved_planned_selections']);
        $this->assertModelMissing($origin);
        $this->assertModelExists($selection);
        $this->assertNull($selection->fresh()->origin_media_asset_id);
        $this->assertNull($selection->fresh()->preview_failed_at);
        $this->assertSame(0, $sourcePost->mediaAssets()->count());
        $this->assertSame('telegram/media/video.mp4', $selection->fresh()->path);
        Storage::disk('local')->assertExists('telegram/media/video.mp4');
    }

    public function test_owner_download_treats_an_already_detached_asset_as_success(): void
    {
        $client = Mockery::mock(MadelineClient::class);
        $client->shouldNotReceive('getChannelMessage');
        $command = new TelegramOwnerCommand([
            'type' => TelegramOwnerCommandType::DownloadMediaPreview,
            'payload' => ['media_asset_id' => 999],
        ]);

        $result = app(TelegramOwnerMediaDownloader::class)
            ->handle($command, $client, true);

        $this->assertTrue($result['detached']);
        $this->assertTrue($result['already_missing']);
        $this->assertSame(999, $result['media_asset_id']);
    }

    public function test_download_job_removes_partial_file_after_telegram_cdn_failure(): void
    {
        Storage::fake('local');
        $account = TelegramAccount::factory()->create();
        $channel = Source::factory()->create([
            'telegram_peer_id' => -100123,
            'username' => null,
            'collector_telegram_account_id' => $account->id,
        ]);
        $sourcePost = SourcePost::factory()->create(['source_id' => $channel->id]);
        $message = $sourcePost->messages()->create([
            'source_id' => $channel->id,
            'external_message_id' => 100,
            'text' => 'Видео',
            'entities' => [],
            'metrics' => [],
            'raw_payload' => [],
            'posted_at' => now(),
        ]);
        $asset = MediaAsset::factory()->for($sourcePost, 'mediable')->create([
            'source_message_id' => $message->id,
            'type' => MediaType::Video,
            'path' => null,
            'checksum' => null,
            'downloaded_at' => null,
            'mime_type' => 'video/mp4',
            'metadata' => ['bot_api_file_id' => 'stale-file-id'],
        ]);
        $client = Mockery::mock(MadelineClient::class);
        $client->shouldReceive('getChannelMessage')
            ->once()
            ->with(-100123, 100)
            ->andReturn([
                '_' => 'message',
                'id' => 100,
                'media' => ['_' => 'messageMediaDocument'],
            ]);
        $client->shouldReceive('downloadToFile')
            ->once()
            ->withArgs(fn (mixed $media): bool => is_array($media) && $media['id'] === 100)
            ->andReturnUsing(function (mixed $media, string $path): never {
                File::put($path, 'partial-video');

                throw new RuntimeException('VOLUME_LOC_NOT_FOUND');
            });
        try {
            $this->runOwnerDownload($asset, $client);
            $this->fail('The Telegram CDN failure should be propagated to the queue worker.');
        } catch (RuntimeException $exception) {
            $this->assertSame('VOLUME_LOC_NOT_FOUND', $exception->getMessage());
        }

        $this->assertNull($asset->fresh()->path);
        $this->assertSame([], Storage::disk('local')->allFiles('telegram/tmp'));
        $this->assertSame([], Storage::disk('local')->allFiles('telegram/media'));
    }

    public function test_download_job_accepts_a_complete_photo_larger_than_its_stale_stored_size(): void
    {
        Storage::fake('local');
        $account = TelegramAccount::factory()->create();
        $channel = Source::factory()->create([
            'telegram_peer_id' => -100123,
            'username' => null,
            'collector_telegram_account_id' => $account->id,
        ]);
        $sourcePost = SourcePost::factory()->create(['source_id' => $channel->id]);
        $message = $sourcePost->messages()->create([
            'source_id' => $channel->id,
            'external_message_id' => 100,
            'text' => 'Фото',
            'entities' => [],
            'metrics' => [],
            'raw_payload' => [],
            'posted_at' => now(),
        ]);
        $asset = MediaAsset::factory()->for($sourcePost, 'mediable')->create([
            'source_message_id' => $message->id,
            'type' => MediaType::Photo,
            'path' => null,
            'checksum' => null,
            'downloaded_at' => null,
            'size_bytes' => 197_205,
            'mime_type' => 'image/jpeg',
        ]);
        $plannedPost = PlannedPost::factory()->create();
        $selectedAsset = MediaAsset::factory()->for($plannedPost, 'mediable')->create([
            'origin_media_asset_id' => $asset->id,
            'type' => MediaType::Photo,
            'path' => null,
            'checksum' => null,
            'downloaded_at' => null,
            'size_bytes' => 197_205,
            'mime_type' => 'image/jpeg',
        ]);
        $client = Mockery::mock(MadelineClient::class);
        $client->shouldReceive('getChannelMessage')
            ->once()
            ->with(-100123, 100)
            ->andReturn([
                '_' => 'message',
                'id' => 100,
                'media' => ['_' => 'messageMediaPhoto'],
            ]);
        $client->shouldReceive('downloadToFile')
            ->once()
            ->andReturnUsing(function (mixed $media, string $path): string {
                File::put($path, str_repeat('x', 332_141));

                return $path;
            });
        $this->runOwnerDownload($asset, $client);

        $asset->refresh();
        $selectedAsset->refresh();
        $this->assertSame(332_141, $asset->size_bytes);
        $this->assertNotNull($asset->path);
        $this->assertSame(332_141, Storage::disk('local')->size($asset->path));
        $this->assertSame(332_141, $selectedAsset->size_bytes);
        $this->assertSame($asset->path, $selectedAsset->path);
    }

    public function test_download_job_trusts_the_downloaded_size_instead_of_stored_telegram_metadata(): void
    {
        Storage::fake('local');
        config(['services.telegram.media_max_bytes' => 10]);
        $account = TelegramAccount::factory()->create();
        $channel = Source::factory()->create([
            'telegram_peer_id' => -100123,
            'username' => null,
            'collector_telegram_account_id' => $account->id,
        ]);
        $sourcePost = SourcePost::factory()->create(['source_id' => $channel->id]);
        $message = $sourcePost->messages()->create([
            'source_id' => $channel->id,
            'external_message_id' => 100,
            'text' => 'Фото',
            'entities' => [],
            'metrics' => [],
            'raw_payload' => [],
            'posted_at' => now(),
        ]);
        $asset = MediaAsset::factory()->for($sourcePost, 'mediable')->create([
            'source_message_id' => $message->id,
            'type' => MediaType::Photo,
            'path' => null,
            'checksum' => null,
            'downloaded_at' => null,
            'size_bytes' => 20,
            'mime_type' => 'image/jpeg',
        ]);
        $client = Mockery::mock(MadelineClient::class);
        $client->shouldReceive('getChannelMessage')
            ->once()
            ->with(-100123, 100)
            ->andReturn([
                '_' => 'message',
                'id' => 100,
                'media' => ['_' => 'messageMediaPhoto'],
            ]);
        $client->shouldReceive('downloadToFile')
            ->once()
            ->andReturnUsing(function (mixed $media, string $path): string {
                File::put($path, 'partial');

                return $path;
            });
        $this->runOwnerDownload($asset, $client);

        $asset->refresh();
        $this->assertNotNull($asset->path);
        $this->assertSame(7, $asset->size_bytes);
        $this->assertSame(7, Storage::disk('local')->size($asset->path));
        $this->assertSame([], Storage::disk('local')->allFiles('telegram/tmp'));
    }

    public function test_download_job_ignores_missing_madeline_lock_during_cleanup(): void
    {
        Storage::fake('local');
        $account = TelegramAccount::factory()->create();
        $channel = Source::factory()->create([
            'telegram_peer_id' => -100123,
            'username' => null,
            'collector_telegram_account_id' => $account->id,
        ]);
        $sourcePost = SourcePost::factory()->create(['source_id' => $channel->id]);
        $message = $sourcePost->messages()->create([
            'source_id' => $channel->id,
            'external_message_id' => 100,
            'text' => 'Фото',
            'entities' => [],
            'metrics' => [],
            'raw_payload' => [],
            'posted_at' => now(),
        ]);
        $asset = MediaAsset::factory()->for($sourcePost, 'mediable')->create([
            'source_message_id' => $message->id,
            'path' => null,
            'checksum' => null,
            'downloaded_at' => null,
            'size_bytes' => strlen('downloaded'),
            'metadata' => ['bot_api_file_id' => 'first-file'],
        ]);
        $client = Mockery::mock(MadelineClient::class);
        $client->shouldReceive('getChannelMessage')
            ->once()
            ->with(-100123, 100)
            ->andReturn([
                '_' => 'message',
                'id' => 100,
                'media' => ['_' => 'messageMediaPhoto'],
            ]);
        $client->shouldReceive('downloadToFile')
            ->once()
            ->andReturnUsing(function (mixed $media, string $path): string {
                File::put($path, 'downloaded');

                return $path;
            });
        set_error_handler(
            static function (int $level, string $message): never {
                throw new RuntimeException($message, $level);
            },
        );

        try {
            $this->runOwnerDownload($asset, $client);
        } finally {
            restore_error_handler();
        }

        $asset->refresh();
        $this->assertNotNull($asset->path);
        $this->assertNull($asset->failed_at);
        Storage::disk('local')->assertExists($asset->path);
    }

    public function test_failed_callback_does_not_reject_an_already_downloaded_asset(): void
    {
        $sourcePost = SourcePost::factory()->create();
        $asset = MediaAsset::factory()->for($sourcePost, 'mediable')->create([
            'path' => 'telegram/media/downloaded.jpg',
            'downloaded_at' => now(),
            'failed_at' => null,
        ]);

        (new DownloadMediaAssetJob($asset->id))->failed(
            new RuntimeException('Temporary lock cleanup failed.'),
        );

        $asset->refresh();
        $this->assertNull($asset->failed_at);
        $this->assertArrayNotHasKey('download_error', $asset->metadata ?? []);
    }

    public function test_existing_video_file_is_synced_to_planned_post_without_additional_processing(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('telegram/video.mp4', 'original-video');
        $sourcePost = SourcePost::factory()->create();
        $sourceAsset = MediaAsset::factory()->for($sourcePost, 'mediable')->create([
            'type' => MediaType::Video,
            'path' => 'telegram/video.mp4',
            'mime_type' => 'video/mp4',
        ]);
        $plannedPost = PlannedPost::factory()->create();
        $selectedAsset = MediaAsset::factory()->for($plannedPost, 'mediable')->create([
            'origin_media_asset_id' => $sourceAsset->id,
            'type' => MediaType::Video,
            'path' => null,
            'downloaded_at' => null,
            'mime_type' => 'video/mp4',
        ]);

        $client = Mockery::mock(MadelineClient::class);
        $client->shouldNotReceive('getChannelMessage');
        $client->shouldNotReceive('downloadToFile');
        $this->runOwnerDownload($selectedAsset, $client);

        $selectedAsset->refresh();
        $this->assertSame('telegram/video.mp4', $selectedAsset->path);
        $this->assertNotNull($selectedAsset->downloaded_at);
        Storage::disk('local')->assertExists($selectedAsset->path);
    }

    public function test_approval_recovers_a_selected_video_path_from_its_downloaded_origin(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('telegram/video.mp4', 'original-video');
        $user = User::factory()->create();
        $plan = ContentPlan::factory()->create([
            'slot_schedule' => [now()->addHour()->toIso8601String()],
        ]);
        $candidate = StoryCandidate::factory()->create(['content_plan_id' => $plan->id]);
        $sourcePost = SourcePost::factory()->create();
        $sourceAsset = MediaAsset::factory()->for($sourcePost, 'mediable')->create([
            'type' => MediaType::Video,
            'path' => 'telegram/video.mp4',
            'mime_type' => 'video/mp4',
        ]);
        $plannedPost = PlannedPost::factory()->create([
            'content_plan_id' => $plan->id,
            'story_candidate_id' => $candidate->id,
            'scheduled_at' => now()->addHour(),
            'status' => PlannedPostStatus::FinalReview,
            'risk_flags' => [],
        ]);
        $selectedAsset = MediaAsset::factory()->for($plannedPost, 'mediable')->create([
            'origin_media_asset_id' => $sourceAsset->id,
            'type' => MediaType::Video,
            'path' => null,
            'downloaded_at' => null,
            'mime_type' => 'video/mp4',
        ]);

        app(ApprovePlannedPost::class)->approve($plannedPost, $user);

        $this->assertSame(PlannedPostStatus::Approved, $plannedPost->fresh()->status);
        $this->assertSame('telegram/video.mp4', $selectedAsset->fresh()->path);
    }

    /** @return array<string, mixed> */
    private function runOwnerDownload(
        MediaAsset $asset,
        MadelineClient $client,
        bool $previewOnly = false,
    ): array {
        $command = new TelegramOwnerCommand([
            'type' => $previewOnly
                ? TelegramOwnerCommandType::DownloadMediaPreview
                : TelegramOwnerCommandType::DownloadMedia,
            'payload' => ['media_asset_id' => $asset->id],
        ]);
        $downloader = app(TelegramOwnerMediaDownloader::class);

        try {
            return $downloader->handle($command, $client, $previewOnly);
        } catch (Throwable $exception) {
            $downloader->recordFailure($command, $exception, $previewOnly);

            throw $exception;
        }
    }

    private function assertOwnerMediaCommand(
        MediaAsset $asset,
        TelegramOwnerCommandType $type,
    ): void {
        $command = TelegramOwnerCommand::query()
            ->where('type', $type)
            ->where('payload->media_asset_id', $asset->id)
            ->firstOrFail();

        $this->assertSame(TelegramOwnerCommandStatus::Pending, $command->status);
        $this->assertSame(3, $command->max_attempts);
    }
}
