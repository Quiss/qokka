<?php

namespace Tests\Feature;

use App\Jobs\DownloadRemoteMediaAssetJob;
use App\Jobs\SyncJsonCollectionSourceJob;
use App\MediaType;
use App\Models\Source;
use App\Models\SourcePost;
use App\Services\JsonCollectionSourceSynchronizer;
use App\Services\MediaFileGarbageCollector;
use App\Services\SourceUrlGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class JsonCollectionSourceSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_imports_collection_skips_invalid_items_and_queues_covers(): void
    {
        Http::preventStrayRequests();
        Queue::fake();
        $source = Source::factory()->jsonCollection()->create([
            'endpoint_url' => 'https://93.184.216.34/api/v1/publications',
            'credentials' => ['authorization' => 'qk_secret'],
        ]);
        Http::fake([
            'https://93.184.216.34/*' => Http::response($this->payload(), 200),
        ]);

        $summary = app(JsonCollectionSourceSynchronizer::class)->handle($source);

        $post = SourcePost::query()->with('mediaAssets')->sole();

        $this->assertSame('collection:collection-1', $post->canonical_key);
        $this->assertStringContainsString('Подборка на вечер', (string) $post->text);
        $this->assertStringContainsString('Первый фильм', (string) $post->text);
        $this->assertCount(1, $post->collectionPayload()['items']);
        $this->assertCount(2, data_get($post->metadata, 'raw_collection.items'));
        $this->assertCount(1, data_get($post->metadata, 'ingest_warnings'));
        $this->assertCount(1, $post->mediaAssets);
        $this->assertSame(1, $summary['created_collections']);
        $this->assertSame(1, $summary['skipped_items']);
        $this->assertSame(1, $summary['queued_covers']);
        $this->assertNull($source->fresh()->last_sync_error);
        $this->assertNotNull($source->fresh()->last_synced_at);
        Queue::assertPushed(
            DownloadRemoteMediaAssetJob::class,
            fn (DownloadRemoteMediaAssetJob $job): bool => $job->mediaAssetId === $post->mediaAssets->sole()->id,
        );
        Http::assertSent(function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $request->header('Authorization')[0] === 'qk_secret'
                && $query === ['hours' => '24', 'limit' => '100'];
        });
    }

    public function test_sync_is_idempotent_and_invalid_refresh_preserves_last_valid_collection(): void
    {
        Queue::fake();
        $source = Source::factory()->jsonCollection()->create([
            'endpoint_url' => 'https://93.184.216.34/api/v1/publications',
        ]);
        $validPayload = $this->payload();
        $invalidPayload = $validPayload;
        $invalidPayload['collections'][0]['title'] = 'Не должно сохраниться';
        $invalidPayload['collections'][0]['items'] = [[
            'work_id' => 'broken',
            'image_url' => 'https://93.184.216.34/images/broken.jpg',
        ]];
        Http::fake([
            'https://93.184.216.34/*' => Http::sequence()
                ->push($validPayload)
                ->push($validPayload)
                ->push($invalidPayload),
        ]);
        $synchronizer = app(JsonCollectionSourceSynchronizer::class);

        $synchronizer->handle($source);
        $synchronizer->handle($source->fresh());
        $summary = $synchronizer->handle($source->fresh());

        $this->assertDatabaseCount('source_posts', 1);
        $this->assertStringContainsString('Подборка на вечер', SourcePost::query()->sole()->text);
        $this->assertStringNotContainsString('Не должно сохраниться', SourcePost::query()->sole()->text);
        $this->assertSame(1, $summary['skipped_collections']);
    }

    public function test_source_and_media_urls_must_be_public_https_without_redirects(): void
    {
        Http::preventStrayRequests();
        $source = Source::factory()->jsonCollection()->create([
            'endpoint_url' => 'https://127.0.0.1/api/v1/publications',
        ]);

        $this->expectException(InvalidArgumentException::class);

        app(JsonCollectionSourceSynchronizer::class)->handle($source);
    }

    public function test_remote_cover_is_downloaded_to_local_storage(): void
    {
        Http::preventStrayRequests();
        Storage::fake('local');
        $source = Source::factory()->jsonCollection()->create();
        $post = SourcePost::factory()->create(['source_id' => $source->id]);
        $asset = $post->mediaAssets()->create([
            'ingest_key' => 'remote:work-1',
            'external_id' => 'work-1',
            'type' => MediaType::Photo,
            'disk' => 'local',
            'metadata' => [
                'transport' => 'remote',
                'remote_url' => 'https://93.184.216.34/images/work-1.jpg',
            ],
        ]);
        Http::fake([
            'https://93.184.216.34/images/work-1.jpg' => Http::response(
                'fake-jpeg-contents',
                200,
                ['Content-Type' => 'image/jpeg'],
            ),
        ]);

        (new DownloadRemoteMediaAssetJob($asset->id))->handle(
            app(SourceUrlGuard::class),
            app(MediaFileGarbageCollector::class),
        );

        $asset->refresh();

        $this->assertNotNull($asset->path);
        $this->assertSame('image/jpeg', $asset->mime_type);
        $this->assertSame(strlen('fake-jpeg-contents'), $asset->size_bytes);
        $this->assertNull($asset->failed_at);
        Storage::disk('local')->assertExists($asset->path);
    }

    public function test_sync_queues_at_most_ten_valid_https_covers(): void
    {
        Queue::fake();
        $source = Source::factory()->jsonCollection()->create([
            'endpoint_url' => 'https://93.184.216.34/api/v1/publications',
        ]);
        $payload = $this->payload();
        $payload['collections'][0]['items'] = collect(range(1, 12))
            ->map(fn (int $number): array => $this->validItem(
                "work-{$number}",
                $number === 1
                    ? 'http://93.184.216.34/images/insecure.jpg'
                    : "https://93.184.216.34/images/work-{$number}.jpg",
            ))
            ->all();
        Http::fake([
            'https://93.184.216.34/*' => Http::response($payload),
        ]);

        $summary = app(JsonCollectionSourceSynchronizer::class)->handle($source);

        $post = SourcePost::query()->with('mediaAssets')->sole();

        $this->assertCount(10, $post->mediaAssets);
        $this->assertSame(range(0, 9), $post->mediaAssets->sortBy('sort_order')->pluck('sort_order')->all());
        $this->assertSame(10, $summary['queued_covers']);
        Queue::assertPushed(DownloadRemoteMediaAssetJob::class, 10);
    }

    public function test_sync_job_records_invalid_contract_error(): void
    {
        $source = Source::factory()->jsonCollection()->create([
            'endpoint_url' => 'https://93.184.216.34/api/v1/publications',
        ]);
        Http::fake([
            'https://93.184.216.34/*' => Http::response(['status' => 'success']),
        ]);

        try {
            (new SyncJsonCollectionSourceJob($source->id))->handle(
                app(JsonCollectionSourceSynchronizer::class),
            );
            $this->fail('Ожидалась ошибка контракта JSON.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('контракту подборок', $exception->getMessage());
        }

        $this->assertStringContainsString(
            'контракту подборок',
            (string) $source->fresh()->last_sync_error,
        );
    }

    public function test_sync_accepts_generic_game_and_single_news_collections(): void
    {
        Queue::fake();
        $source = Source::factory()->jsonCollection()->create([
            'endpoint_url' => 'https://93.184.216.34/feed',
        ]);
        Http::fake([
            'https://93.184.216.34/*' => Http::response([
                'status' => 'success',
                'collections' => [[
                    'collection_id' => 'games-1',
                    'title' => 'Лучшие Souls игры',
                    'items' => [[
                        'title' => 'Игра 1',
                        'description' => 'Сложная ролевая игра.',
                        'posted' => '2026-08-01T12:00:00Z',
                    ]],
                    'created_at' => '2026-08-01T17:10:12Z',
                ], [
                    'collection_id' => 'news-1',
                    'title' => 'Криптовалюта дала -30%',
                    'items' => [[
                        'title' => 'Криптовалюта дала -30%',
                        'text' => 'Рынок резко снизился за сутки.',
                        'link' => 'https://example.com/news/crypto-drop',
                    ]],
                    'created_at' => '2026-08-01T18:10:12Z',
                ]],
            ]),
        ]);

        app(JsonCollectionSourceSynchronizer::class)->handle($source);

        $posts = SourcePost::query()->orderBy('canonical_key')->get();

        $this->assertCount(2, $posts);
        $this->assertStringContainsString('Сложная ролевая игра.', $posts[0]->text);
        $this->assertStringContainsString('опубликовано: 2026-08-01T12:00:00Z', $posts[0]->text);
        $this->assertStringContainsString('Рынок резко снизился за сутки.', $posts[1]->text);
        $this->assertStringStartsWith('item-', $posts[0]->collectionPayload()['items'][0]['work_id']);
        $this->assertNull($posts[0]->collectionPayload()['items'][0]['rating']);
        $this->assertNull($posts[0]->collectionPayload()['items'][0]['year']);
        $this->assertSame(
            'https://example.com/news/crypto-drop',
            $posts[1]->collectionPayload()['items'][0]['url'],
        );
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'status' => 'success',
            'collections' => [[
                'collection_id' => 'collection-1',
                'title' => 'Подборка на вечер',
                'created_at' => now()->subHour()->toIso8601String(),
                'items' => [[
                    'work_id' => 'work-1',
                    'external_ids' => ['kinopoisk' => '123'],
                    'image_url' => 'https://93.184.216.34/images/work-1.jpg',
                    'text' => 'Описание первого фильма.',
                    'title' => 'Первый фильм',
                    'year' => 2025,
                    'rating' => [
                        'value' => 8.5,
                        'scale' => 10,
                        'source' => 'kinopoisk',
                        'votes' => 1000,
                    ],
                    'ratings' => [],
                    'duration_minutes' => 100,
                    'duration_scope' => 'total',
                ], [
                    'work_id' => 'work-without-required-content',
                    'image_url' => 'https://93.184.216.34/images/broken.jpg',
                ]],
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private function validItem(string $workId, string $imageUrl): array
    {
        return [
            'work_id' => $workId,
            'image_url' => $imageUrl,
            'text' => 'Описание фильма.',
            'title' => 'Фильм '.$workId,
            'year' => 2025,
            'rating' => [
                'value' => 8.5,
                'scale' => 10,
                'source' => 'kinopoisk',
            ],
        ];
    }
}
