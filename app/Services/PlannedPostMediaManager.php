<?php

namespace App\Services;

use App\Jobs\DownloadMediaAssetJob;
use App\MediaType;
use App\Models\MediaAsset;
use App\Models\PlannedPost;
use App\Models\StoryCandidate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PlannedPostMediaManager
{
    public function copyDefaultSelection(PlannedPost $plannedPost, StoryCandidate $candidate): void
    {
        $candidate->loadMissing('sourcePosts.mediaAssets');
        $primarySource = $candidate->sourcePosts->firstWhere('pivot.is_primary', true)
            ?? $candidate->sourcePosts->first();

        if ($primarySource === null) {
            return;
        }

        $sourceAssetIds = $primarySource->mediaAssets
            ->map(fn (MediaAsset $mediaAsset): int => $mediaAsset->id)
            ->values()
            ->all();

        $this->replaceSelection($plannedPost, array_values($sourceAssetIds));
    }

    /** @param list<int> $sourceAssetIds */
    public function replaceSelection(PlannedPost $plannedPost, array $sourceAssetIds): void
    {
        if (count($sourceAssetIds) > 10) {
            throw ValidationException::withMessages([
                'media_asset_ids' => 'В одной публикации можно выбрать не более 10 фото или видео.',
            ]);
        }

        $plannedPost->loadMissing('storyCandidate.sourcePosts.mediaAssets');
        $allowedAssets = $plannedPost->storyCandidate->sourcePosts
            ->flatMap->mediaAssets
            ->whereIn('type', [MediaType::Photo, MediaType::Video])
            ->keyBy('id');
        $selectedAssets = collect($sourceAssetIds)
            ->unique()
            ->map(fn (int $id): ?MediaAsset => $allowedAssets->get($id))
            ->filter()
            ->values();

        if ($selectedAssets->count() !== collect($sourceAssetIds)->unique()->count()) {
            throw ValidationException::withMessages([
                'media_asset_ids' => 'Выбрано медиа, которое не относится к источникам этой новости.',
            ]);
        }

        $maxBytes = (int) config('services.telegram.media_max_bytes', 50 * 1024 * 1024);
        $oversized = $selectedAssets->first(
            fn (MediaAsset $asset): bool => $asset->size_bytes !== null && $asset->size_bytes > $maxBytes,
        );

        if ($oversized !== null) {
            throw ValidationException::withMessages([
                'media_asset_ids' => 'Файл превышает лимит Telegram 50 МБ и не может быть выбран.',
            ]);
        }

        DB::transaction(function () use ($plannedPost, $selectedAssets): void {
            $plannedPost->mediaAssets()->delete();

            $selectedAssets->each(function (MediaAsset $asset, int $index) use ($plannedPost): void {
                $clone = $plannedPost->mediaAssets()->create([
                    'source_message_id' => $asset->source_message_id,
                    'origin_media_asset_id' => $asset->id,
                    'external_id' => $asset->external_id,
                    'type' => $asset->type,
                    'disk' => $asset->disk,
                    'path' => $asset->path,
                    'preview_disk' => $asset->preview_disk,
                    'preview_path' => $asset->preview_path,
                    'preview_mime_type' => $asset->preview_mime_type,
                    'mime_type' => $asset->mime_type,
                    'size_bytes' => $asset->size_bytes,
                    'checksum' => $asset->checksum,
                    'sort_order' => $index,
                    'metadata' => $asset->metadata,
                    'downloaded_at' => $asset->downloaded_at,
                    'preview_downloaded_at' => $asset->preview_downloaded_at,
                    'failed_at' => $asset->failed_at,
                    'preview_failed_at' => $asset->preview_failed_at,
                ]);

                if ($clone->path === null) {
                    DownloadMediaAssetJob::dispatch($clone->id)->onQueue('telegram')->afterCommit();
                }
            });
        });
    }

    public function hasUnpreparedSelection(PlannedPost $plannedPost): bool
    {
        return $plannedPost->mediaAssets()
            ->whereNull('path')
            ->exists();
    }

    public function syncAvailableOrigins(PlannedPost $plannedPost): void
    {
        $plannedPost->mediaAssets()
            ->whereNull('path')
            ->with('originMediaAsset')
            ->get()
            ->each(function (MediaAsset $selectedAsset): void {
                $origin = $selectedAsset->originMediaAsset;

                if ($origin === null || blank($origin->path)) {
                    return;
                }

                $selectedAsset->update([
                    'disk' => $origin->disk,
                    'path' => $origin->path,
                    'preview_disk' => $origin->preview_disk,
                    'preview_path' => $origin->preview_path,
                    'preview_mime_type' => $origin->preview_mime_type,
                    'checksum' => $origin->checksum,
                    'downloaded_at' => $origin->downloaded_at,
                    'preview_downloaded_at' => $origin->preview_downloaded_at,
                    'failed_at' => $origin->failed_at,
                    'preview_failed_at' => $origin->preview_failed_at,
                    'metadata' => $origin->metadata,
                ]);
            });
    }

    /** @return Collection<int, MediaAsset> */
    public function availableAssets(PlannedPost $plannedPost): Collection
    {
        $plannedPost->loadMissing('storyCandidate.sourcePosts.sourceChannel', 'storyCandidate.sourcePosts.mediaAssets');

        return $plannedPost->storyCandidate->sourcePosts
            ->flatMap(function ($sourcePost) {
                return $sourcePost->mediaAssets->map(function (MediaAsset $asset) use ($sourcePost): MediaAsset {
                    $asset->setAttribute('source_label', $sourcePost->sourceChannel->title);
                    $asset->setAttribute('source_url', $sourcePost->source_url);

                    return $asset;
                });
            })
            ->whereIn('type', [MediaType::Photo, MediaType::Video])
            ->values();
    }
}
