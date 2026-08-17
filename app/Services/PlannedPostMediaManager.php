<?php

namespace App\Services;

use App\Actions\QueueMediaAssetDownloadRetries;
use App\MediaType;
use App\Models\MediaAsset;
use App\Models\PlannedPost;
use App\Models\StoryCandidate;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class PlannedPostMediaManager
{
    public function __construct(
        private readonly QueueMediaAssetDownloadRetries $queueMediaAssetDownloadRetries,
        private readonly MediaFileGarbageCollector $mediaFileGarbageCollector,
    ) {}

    public function copyDefaultSelection(PlannedPost $plannedPost, StoryCandidate $candidate): void
    {
        $candidate->loadMissing('sourcePosts.mediaAssets');
        $primarySource = $candidate->sourcePosts->firstWhere('pivot.is_primary', true);
        $orderedSources = collect([$primarySource])
            ->filter()
            ->merge($candidate->sourcePosts->reject(
                fn ($sourcePost): bool => $sourcePost->id === $primarySource?->id,
            ));
        $sourceAssetIds = [];

        foreach ($orderedSources as $sourcePost) {
            $sourceAssetIds = $this->defaultAssetIds($sourcePost->mediaAssets);

            if ($sourceAssetIds !== []) {
                break;
            }
        }

        $this->replaceSelection($plannedPost, $sourceAssetIds);
    }

    /** @param list<int> $sourceAssetIds */
    public function replaceSelection(PlannedPost $plannedPost, array $sourceAssetIds): void
    {
        $this->replaceEditorSelection(
            $plannedPost,
            array_map(fn (int $id): string => 'source:'.$id, $sourceAssetIds),
        );
    }

    /**
     * @param  list<string>  $selectionTokens
     * @param  array<array-key, UploadedFile>  $uploads
     */
    public function replaceEditorSelection(
        PlannedPost $plannedPost,
        array $selectionTokens,
        array $uploads = [],
        ?int $uploadedById = null,
    ): void {
        $selectionTokens = array_values(array_unique($selectionTokens));

        if (count($selectionTokens) > 10) {
            throw ValidationException::withMessages([
                'media_asset_ids' => 'В одной публикации можно выбрать не более 10 фото, видео или GIF.',
            ]);
        }

        $plannedPost->loadMissing('mediaAssets', 'storyCandidate.sourcePosts.mediaAssets');
        $allowedAssets = $plannedPost->storyCandidate->sourcePosts
            ->flatMap->mediaAssets
            ->whereIn('type', MediaType::publishableCases())
            ->keyBy('id');
        $customAssets = $plannedPost->mediaAssets
            ->whereNull('origin_media_asset_id')
            ->keyBy('id');
        $uploadsByFilename = collect($uploads)
            ->keyBy(fn (UploadedFile $upload): string => $upload->getFilename());
        $selectedItems = collect($selectionTokens)->map(function (string $token) use ($allowedAssets, $customAssets, $uploadsByFilename): array {
            if (preg_match('/^source:(\d+)$/', $token, $matches) === 1) {
                $asset = $allowedAssets->get((int) $matches[1]);

                if (! $asset instanceof MediaAsset) {
                    $this->invalidSelection();
                }

                $this->ensureSelectable($asset);

                return [
                    'kind' => 'source',
                    'asset' => $asset,
                    'upload' => null,
                    'type' => $asset->type,
                ];
            }

            if (preg_match('/^custom:(\d+)$/', $token, $matches) === 1) {
                $asset = $customAssets->get((int) $matches[1]);

                if (! $asset instanceof MediaAsset) {
                    $this->invalidSelection();
                }

                $this->ensureSelectable($asset);

                return [
                    'kind' => 'custom',
                    'asset' => $asset,
                    'upload' => null,
                    'type' => $asset->type,
                ];
            }

            if (str_starts_with($token, 'upload:')) {
                $upload = $uploadsByFilename->get(substr($token, 7));

                if (! $upload instanceof UploadedFile) {
                    $this->invalidSelection();
                }

                return [
                    'kind' => 'upload',
                    'asset' => null,
                    'upload' => $upload,
                    'type' => $this->uploadedMediaType($upload),
                ];
            }

            $this->invalidSelection();
        })->values();

        if ($selectedItems->count() > 1 && $selectedItems->contains(
            fn (array $item): bool => $this->itemType($item) === MediaType::Animation,
        )) {
            throw ValidationException::withMessages([
                'media_asset_ids' => 'GIF-анимацию можно публиковать только отдельно от других медиа.',
            ]);
        }

        $storedUploads = [];

        try {
            foreach ($selectedItems->where('kind', 'upload') as $item) {
                $upload = $item['upload'];

                if (! $upload instanceof UploadedFile) {
                    $this->invalidSelection();
                }

                $uploadId = spl_object_id($upload);
                $mimeType = $upload->getMimeType();
                $size = $upload->getSize();
                $originalName = $upload->getClientOriginalName();
                $path = $upload->store('editorial/planned-posts/'.$plannedPost->id, 'local');

                if (! is_string($path)) {
                    throw new RuntimeException('Не удалось сохранить загруженное медиа.');
                }

                $checksum = hash_file('sha256', Storage::disk('local')->path($path));

                if (! is_string($checksum)) {
                    throw new RuntimeException('Не удалось проверить загруженное медиа.');
                }

                $storedUploads[$uploadId] = [
                    'path' => $path,
                    'checksum' => $checksum,
                    'mime_type' => $mimeType,
                    'size' => $size,
                    'original_name' => $originalName,
                ];
            }

            $removedPaths = DB::transaction(function () use ($plannedPost, $selectedItems, $storedUploads, $uploadedById): array {
                $existingMedia = $plannedPost->mediaAssets()->get();
                $pathsToCheck = $this->mediaFileGarbageCollector->pathsFor(
                    $existingMedia->whereNull('origin_media_asset_id'),
                );
                $selectedCustomIds = [];

                $plannedPost->mediaAssets()->whereNotNull('origin_media_asset_id')->delete();

                foreach ($selectedItems as $index => $item) {
                    if ($item['kind'] === 'source') {
                        $asset = $item['asset'];

                        if (! $asset instanceof MediaAsset) {
                            $this->invalidSelection();
                        }

                        $plannedPost->mediaAssets()->create($this->sourceAssetAttributes($asset, $index));

                        if ($asset->path === null) {
                            DB::afterCommit(fn () => $this->queueMediaAssetDownloadRetries->handle(collect([$asset])));
                        }

                        continue;
                    }

                    if ($item['kind'] === 'custom') {
                        $asset = $item['asset'];

                        if (! $asset instanceof MediaAsset) {
                            $this->invalidSelection();
                        }

                        $asset->update(['sort_order' => $index]);
                        $selectedCustomIds[] = $asset->id;

                        continue;
                    }

                    $upload = $item['upload'];

                    if (! $upload instanceof UploadedFile) {
                        $this->invalidSelection();
                    }

                    $stored = $storedUploads[spl_object_id($upload)];
                    $asset = $plannedPost->mediaAssets()->create([
                        'type' => $item['type'],
                        'disk' => 'local',
                        'path' => $stored['path'],
                        'preview_disk' => $item['type'] === MediaType::Animation ? 'local' : null,
                        'preview_path' => $item['type'] === MediaType::Animation ? $stored['path'] : null,
                        'preview_mime_type' => $item['type'] === MediaType::Animation ? $stored['mime_type'] : null,
                        'mime_type' => $stored['mime_type'],
                        'size_bytes' => $stored['size'],
                        'checksum' => $stored['checksum'],
                        'sort_order' => $index,
                        'metadata' => [
                            'editor_upload' => true,
                            'original_name' => $stored['original_name'],
                            'uploaded_by_id' => $uploadedById,
                        ],
                        'downloaded_at' => now(),
                        'preview_downloaded_at' => $item['type'] === MediaType::Animation ? now() : null,
                    ]);
                    $selectedCustomIds[] = $asset->id;
                }

                $obsoleteCustom = $plannedPost->mediaAssets()->whereNull('origin_media_asset_id');

                if ($selectedCustomIds !== []) {
                    $obsoleteCustom->whereNotIn('id', $selectedCustomIds);
                }

                $obsoleteCustom->delete();

                return $pathsToCheck;
            });

            DB::afterCommit(fn () => $this->mediaFileGarbageCollector->deleteUnreferenced($removedPaths));
        } catch (Throwable $exception) {
            foreach ($storedUploads as $storedUpload) {
                Storage::disk('local')->delete($storedUpload['path']);
            }

            throw $exception;
        }
    }

    public function hasUnpreparedSelection(PlannedPost $plannedPost): bool
    {
        return $plannedPost->mediaAssets()
            ->whereNull('path')
            ->exists();
    }

    public function hasFailedSelection(PlannedPost $plannedPost): bool
    {
        return $plannedPost->mediaAssets()
            ->whereNull('path')
            ->whereNotNull('failed_at')
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

    public function queueUnpreparedSelectionDownloads(PlannedPost $plannedPost): int
    {
        return $this->queueMediaAssetDownloadRetries->handle(
            $plannedPost->mediaAssets()
                ->whereNull('path')
                ->get(),
        );
    }

    /** @return Collection<int, MediaAsset> */
    public function availableAssets(PlannedPost $plannedPost): Collection
    {
        $plannedPost->loadMissing('mediaAssets', 'storyCandidate.sourcePosts.source', 'storyCandidate.sourcePosts.mediaAssets');

        $sourceAssets = $plannedPost->storyCandidate->sourcePosts
            ->flatMap(function ($sourcePost) {
                return $sourcePost->mediaAssets->map(function (MediaAsset $asset) use ($sourcePost): MediaAsset {
                    $asset->setAttribute('source_label', $sourcePost->source->title);
                    $asset->setAttribute('source_url', $sourcePost->source_url);
                    $asset->setAttribute('selection_token', 'source:'.$asset->id);
                    $asset->setAttribute('is_custom', false);

                    return $asset;
                });
            })
            ->whereIn('type', MediaType::publishableCases());
        $customAssets = $plannedPost->mediaAssets
            ->whereNull('origin_media_asset_id')
            ->map(function (MediaAsset $asset): MediaAsset {
                $asset->setAttribute('source_label', 'Своё медиа');
                $asset->setAttribute('source_url', null);
                $asset->setAttribute('selection_token', 'custom:'.$asset->id);
                $asset->setAttribute('is_custom', true);

                return $asset;
            });

        return $customAssets
            ->concat($sourceAssets)
            ->each(function (MediaAsset $asset): void {
                $asset->setAttribute('is_selectable', $this->isSelectable($asset));
                $asset->setAttribute('unavailable_reason', $this->unavailableReason($asset));
            })
            ->values();
    }

    /**
     * @param  Collection<int, MediaAsset>  $assets
     * @return list<int>
     */
    private function defaultAssetIds(Collection $assets): array
    {
        $selectable = $assets
            ->whereIn('type', MediaType::publishableCases())
            ->filter($this->isSelectable(...));
        $album = $selectable
            ->reject(fn (MediaAsset $asset): bool => $asset->type === MediaType::Animation)
            ->take(10)
            ->pluck('id')
            ->all();

        if ($album !== []) {
            return array_values(array_map('intval', $album));
        }

        $animation = $selectable->firstWhere('type', MediaType::Animation);

        return $animation instanceof MediaAsset ? [$animation->id] : [];
    }

    private function isSelectable(MediaAsset $asset): bool
    {
        return in_array($asset->type, MediaType::publishableCases(), true)
            && $asset->size_bytes !== null
            && $asset->size_bytes <= $this->maxBytes();
    }

    private function ensureSelectable(MediaAsset $asset): void
    {
        if ($this->isSelectable($asset)) {
            return;
        }

        throw ValidationException::withMessages([
            'media_asset_ids' => $this->unavailableReason($asset) ?? 'Этот файл нельзя выбрать для публикации.',
        ]);
    }

    private function unavailableReason(MediaAsset $asset): ?string
    {
        $maxFileSize = Number::fileSize($this->maxBytes(), maxPrecision: 2);

        if ($asset->size_bytes === null) {
            return "Размер файла неизвестен, поэтому его нельзя выбрать. Лимит — {$maxFileSize}.";
        }

        if ($asset->size_bytes > $this->maxBytes()) {
            $fileSize = Number::fileSize($asset->size_bytes, maxPrecision: 2);

            return "Файл размером {$fileSize} превышает лимит {$maxFileSize} и не может быть выбран.";
        }

        return null;
    }

    private function maxBytes(): int
    {
        return (int) config('services.telegram.media_max_bytes', 50 * 1024 * 1024);
    }

    private function invalidSelection(): never
    {
        throw ValidationException::withMessages([
            'media_asset_ids' => 'Выбрано медиа, которое недоступно для этой публикации.',
        ]);
    }

    private function uploadedMediaType(UploadedFile $upload): MediaType
    {
        if ($upload->getSize() > $this->maxBytes()) {
            $fileSize = Number::fileSize($upload->getSize(), maxPrecision: 2);
            $maxFileSize = Number::fileSize($this->maxBytes(), maxPrecision: 2);

            throw ValidationException::withMessages([
                'custom_media_uploads' => "Файл размером {$fileSize} превышает лимит {$maxFileSize}.",
            ]);
        }

        return match ($upload->getMimeType()) {
            'image/jpeg', 'image/png', 'image/webp' => MediaType::Photo,
            'image/gif' => MediaType::Animation,
            'video/mp4' => MediaType::Video,
            default => throw ValidationException::withMessages([
                'custom_media_uploads' => 'Разрешены JPEG, PNG, WebP, GIF и MP4.',
            ]),
        };
    }

    /**
     * @param  array{kind: string, asset: MediaAsset|null, upload: UploadedFile|null, type: MediaType}  $item
     */
    private function itemType(array $item): MediaType
    {
        return $item['type'];
    }

    /** @return array<string, mixed> */
    private function sourceAssetAttributes(MediaAsset $asset, int $sortOrder): array
    {
        return [
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
            'sort_order' => $sortOrder,
            'metadata' => $asset->metadata,
            'downloaded_at' => $asset->downloaded_at,
            'preview_downloaded_at' => $asset->preview_downloaded_at,
            'failed_at' => $asset->failed_at,
            'preview_failed_at' => $asset->preview_failed_at,
        ];
    }
}
