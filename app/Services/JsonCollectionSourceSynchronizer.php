<?php

namespace App\Services;

use App\Actions\DetachUnavailableSourceMedia;
use App\Jobs\DownloadRemoteMediaAssetJob;
use App\MediaType;
use App\Models\MediaAsset;
use App\Models\Source;
use App\Models\SourcePost;
use App\SourceType;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class JsonCollectionSourceSynchronizer
{
    public function __construct(
        private readonly SourceUrlGuard $urlGuard,
        private readonly DetachUnavailableSourceMedia $detachUnavailableMedia,
        private readonly MediaFileGarbageCollector $mediaFileGarbageCollector,
    ) {}

    /** @return array<string, int> */
    public function handle(Source $source): array
    {
        if ($source->type !== SourceType::JsonCollection || blank($source->endpoint_url)) {
            throw new RuntimeException('Источник не настроен как JSON-подборка.');
        }

        $endpointUrl = $this->urlGuard->ensurePublicHttps($source->endpoint_url);
        $response = $this->request($source)
            ->get($endpointUrl, [
                'hours' => $source->lookbackHours(),
                'limit' => $source->requestLimit(),
            ]);

        if ($response->status() >= 300 && $response->status() < 400) {
            throw new RuntimeException('Редиректы endpoint источника запрещены.');
        }

        $response->throw();
        $payload = $response->json();

        if (
            ! is_array($payload)
            || ($payload['status'] ?? null) !== 'success'
            || ! is_array($payload['collections'] ?? null)
            || ! array_is_list($payload['collections'])
        ) {
            throw new RuntimeException('Источник вернул JSON, не соответствующий контракту подборок.');
        }

        $collections = [];
        $skippedCollections = 0;
        $skippedItems = 0;

        foreach ($payload['collections'] as $index => $rawCollection) {
            $collection = $this->normalizeCollection($rawCollection, $index);

            if ($collection === null) {
                $skippedCollections++;

                continue;
            }

            $skippedItems += $collection['skipped_items'];
            $collections[] = $collection;
        }

        $summary = [
            'received_collections' => count($payload['collections']),
            'created_collections' => 0,
            'updated_collections' => 0,
            'skipped_collections' => $skippedCollections,
            'skipped_items' => $skippedItems,
            'queued_covers' => 0,
        ];
        $queuedMediaIds = [];
        $staleMediaIds = [];
        $oldPaths = [];

        DB::transaction(function () use (
            $source,
            $collections,
            &$summary,
            &$queuedMediaIds,
            &$staleMediaIds,
            &$oldPaths,
        ): void {
            $lockedSource = Source::query()->lockForUpdate()->findOrFail($source->id);
            $latestEventAt = $lockedSource->last_event_at;

            foreach ($collections as $collection) {
                $sourcePost = SourcePost::query()->firstOrNew([
                    'source_id' => $lockedSource->id,
                    'canonical_key' => 'collection:'.$collection['collection_id'],
                ]);
                $wasExisting = $sourcePost->exists;
                $sourcePost->fill([
                    'text' => $this->collectionText($collection),
                    'normalized_text' => Str::lower(Str::squish($this->collectionText($collection))),
                    'source_url' => $lockedSource->endpoint_url,
                    'metrics' => ['available' => false],
                    'views' => 0,
                    'forwards' => 0,
                    'reactions' => 0,
                    'comments' => 0,
                    'metadata' => [
                        'content_kind' => 'collection',
                        'collection' => [
                            'collection_id' => $collection['collection_id'],
                            'title' => $collection['title'],
                            'created_at' => $collection['created_at']->toIso8601String(),
                            'items' => $collection['items'],
                        ],
                        'raw_collection' => $collection['raw_collection'],
                        'ingest_warnings' => $collection['warnings'],
                    ],
                    'status' => 'active',
                    'posted_at' => $collection['created_at'],
                    'edited_at' => $wasExisting ? now() : null,
                    'deleted_at' => null,
                ]);
                $sourcePost->save();

                $summary[$wasExisting ? 'updated_collections' : 'created_collections']++;
                $latestEventAt = $latestEventAt === null || $collection['created_at']->greaterThan($latestEventAt)
                    ? $collection['created_at']
                    : $latestEventAt;

                $remoteAssets = $sourcePost->mediaAssets()
                    ->where('metadata->transport', 'remote')
                    ->get()
                    ->keyBy('ingest_key');
                $wantedIngestKeys = [];

                $coverSortOrder = 0;

                foreach ($collection['items'] as $item) {
                    $remoteUrl = $item['image_url'];

                    if (! is_string($remoteUrl) || ! $this->isHttpsUrl($remoteUrl)) {
                        continue;
                    }

                    if ($coverSortOrder >= 10) {
                        break;
                    }

                    $ingestKey = 'remote:'.$item['work_id'];
                    $wantedIngestKeys[] = $ingestKey;
                    $mediaAsset = $remoteAssets->get($ingestKey)
                        ?? $sourcePost->mediaAssets()->make(['ingest_key' => $ingestKey]);
                    $previousUrl = data_get($mediaAsset->metadata, 'remote_url');

                    if ($mediaAsset->exists && $previousUrl !== $remoteUrl) {
                        $oldPaths = array_merge(
                            $oldPaths,
                            $this->mediaFileGarbageCollector->pathsFor(collect([$mediaAsset])),
                        );
                        $mediaAsset->fill([
                            'path' => null,
                            'mime_type' => null,
                            'size_bytes' => null,
                            'checksum' => null,
                            'downloaded_at' => null,
                        ]);
                    }

                    $mediaAsset->fill([
                        'external_id' => $item['work_id'],
                        'type' => MediaType::Photo,
                        'disk' => 'local',
                        'sort_order' => $coverSortOrder,
                        'metadata' => [
                            'transport' => 'remote',
                            'remote_url' => $remoteUrl,
                            'work_id' => $item['work_id'],
                            'item_title' => $item['title'],
                        ],
                        'failed_at' => null,
                    ]);
                    $mediaAsset->save();

                    if (blank($mediaAsset->path)) {
                        $queuedMediaIds[] = $mediaAsset->id;
                    }

                    $coverSortOrder++;
                }

                $staleMediaIds = array_merge(
                    $staleMediaIds,
                    $remoteAssets->except($wantedIngestKeys)->modelKeys(),
                );
            }

            $summary['queued_covers'] = count(array_unique($queuedMediaIds));
            $lockedSource->update([
                'last_event_at' => $latestEventAt,
                'last_synced_at' => now(),
                'last_sync_error' => null,
                'last_sync_summary' => $summary,
            ]);
        });

        foreach (array_unique($staleMediaIds) as $mediaAssetId) {
            $mediaAsset = MediaAsset::query()->find($mediaAssetId);

            if ($mediaAsset !== null) {
                $this->detachUnavailableMedia->handle($mediaAsset);
            }
        }

        $this->mediaFileGarbageCollector->deleteUnreferenced($oldPaths);

        foreach (array_unique($queuedMediaIds) as $mediaAssetId) {
            DownloadRemoteMediaAssetJob::dispatch($mediaAssetId)->onQueue('ingest');
        }

        return $summary;
    }

    private function request(Source $source): PendingRequest
    {
        $request = Http::acceptJson()
            ->connectTimeout((int) config('channelbot.sources.connect_timeout', 5))
            ->timeout((int) config('channelbot.sources.request_timeout', 30))
            ->withOptions(['allow_redirects' => false]);

        if ($source->authorization() !== null) {
            $request->withHeaders(['Authorization' => 'Bearer ' . $source->authorization()]);
        }

        return $request;
    }

    /**
     * @return array{
     *   collection_id: string,
     *   title: string,
     *   created_at: CarbonImmutable,
     *   items: list<array<string, mixed>>,
     *   raw_collection: array<string, mixed>,
     *   warnings: list<string>,
     *   skipped_items: int
     * }|null
     */
    private function normalizeCollection(mixed $rawCollection, int $collectionIndex): ?array
    {
        if (! is_array($rawCollection)) {
            return null;
        }

        $collectionId = $rawCollection['collection_id'] ?? null;
        $title = $rawCollection['title'] ?? null;
        $createdAt = $rawCollection['created_at'] ?? null;
        $rawItems = $rawCollection['items'] ?? null;

        if (
            ! is_string($collectionId)
            || blank($collectionId)
            || ! is_string($title)
            || blank($title)
            || ! is_string($createdAt)
            || ! is_array($rawItems)
        ) {
            return null;
        }

        try {
            $parsedCreatedAt = CarbonImmutable::parse($createdAt);
        } catch (\Throwable) {
            return null;
        }

        $items = [];
        $warnings = [];

        foreach ($rawItems as $itemIndex => $rawItem) {
            $item = $this->normalizeItem($rawItem, $itemIndex);

            if ($item === null) {
                $warnings[] = "collections[{$collectionIndex}].items[{$itemIndex}] пропущен: обязательные поля неполны.";

                continue;
            }

            $items[] = $item;
        }

        if ($items === []) {
            return null;
        }

        return [
            'collection_id' => $collectionId,
            'title' => $title,
            'created_at' => $parsedCreatedAt,
            'items' => $items,
            'raw_collection' => $rawCollection,
            'warnings' => $warnings,
            'skipped_items' => count($rawItems) - count($items),
        ];
    }

    /** @return array<string, mixed>|null */
    private function normalizeItem(mixed $rawItem, int $itemIndex): ?array
    {
        if (! is_array($rawItem)) {
            return null;
        }

        $title = $rawItem['title'] ?? null;
        $description = $rawItem['text'] ?? $rawItem['description'] ?? null;

        if (
            ! is_string($title)
            || blank($title)
            || ! is_string($description)
            || blank($description)
        ) {
            return null;
        }

        $itemUrl = $rawItem['url'] ?? $rawItem['source_url'] ?? $rawItem['link'] ?? null;
        $explicitId = $rawItem['work_id'] ?? $rawItem['id'] ?? $rawItem['guid'] ?? null;
        $workId = is_string($explicitId) && filled($explicitId)
            ? $explicitId
            : 'item-'.hash('sha256', implode('|', [
                $title,
                is_string($itemUrl) ? $itemUrl : '',
                (string) $itemIndex,
            ]));
        $rating = is_array($rawItem['rating'] ?? null) ? $rawItem['rating'] : null;
        $ratingValue = data_get($rawItem, 'rating.value');
        $ratingScale = data_get($rawItem, 'rating.scale');
        $ratingSource = data_get($rawItem, 'rating.source');
        $normalizedRating = $rating !== null
            && is_numeric($ratingValue)
            && is_numeric($ratingScale)
            && is_string($ratingSource)
            && filled($ratingSource)
                ? [
                    'value' => (float) $ratingValue,
                    'scale' => (float) $ratingScale,
                    'source' => $ratingSource,
                    'votes' => is_numeric(data_get($rawItem, 'rating.votes'))
                        ? (int) data_get($rawItem, 'rating.votes')
                        : null,
                ]
                : null;

        return [
            'work_id' => $workId,
            'title' => $title,
            'description' => $description,
            'year' => is_numeric($rawItem['year'] ?? null) ? (int) $rawItem['year'] : null,
            'rating' => $normalizedRating,
            'ratings' => is_array($rawItem['ratings'] ?? null) ? $rawItem['ratings'] : [],
            'external_ids' => is_array($rawItem['external_ids'] ?? null) ? $rawItem['external_ids'] : [],
            'image_url' => $this->firstString($rawItem, ['image_url', 'cover_url']),
            'url' => is_string($itemUrl) ? $itemUrl : null,
            'posted' => $this->firstString($rawItem, ['posted', 'published_at', 'pub_date']),
            'duration_minutes' => is_numeric($rawItem['duration_minutes'] ?? null)
                ? (int) $rawItem['duration_minutes']
                : null,
            'duration_scope' => is_string($rawItem['duration_scope'] ?? null)
                ? $rawItem['duration_scope']
                : null,
        ];
    }

    /** @param array{title: string, items: list<array<string, mixed>>} $collection */
    private function collectionText(array $collection): string
    {
        $items = collect($collection['items'])
            ->map(function (array $item, int $index): string {
                $details = collect([
                    filled($item['year']) ? (string) $item['year'] : null,
                    is_array($item['rating'])
                        ? sprintf(
                            '%s/%s, %s',
                            $item['rating']['value'],
                            $item['rating']['scale'],
                            $item['rating']['source'],
                        )
                        : null,
                    filled($item['posted']) ? 'опубликовано: '.$item['posted'] : null,
                ])->filter()->implode(', ');
                $heading = sprintf('%d. %s', $index + 1, $item['title']);

                return $heading.($details !== '' ? ' — '.$details : '')."\n".$item['description'];
            })
            ->implode("\n\n");

        return $collection['title']."\n\n".$items;
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<string>  $keys
     */
    private function firstString(array $values, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (is_string($values[$key] ?? null) && filled($values[$key])) {
                return $values[$key];
            }
        }

        return null;
    }

    private function isHttpsUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && parse_url($url, PHP_URL_SCHEME) === 'https'
            && is_string(parse_url($url, PHP_URL_HOST));
    }
}
