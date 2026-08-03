<?php

namespace App\Actions;

use App\Models\MediaAsset;
use App\Services\MediaFileGarbageCollector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class DetachUnavailableSourceMedia
{
    public function __construct(
        private readonly MediaFileGarbageCollector $mediaFileGarbageCollector,
    ) {}

    /**
     * @return array{
     *     media_asset_id: int,
     *     detached: true,
     *     already_missing?: true,
     *     removed_planned_selections: int,
     *     preserved_planned_selections: int
     * }
     */
    public function handle(MediaAsset $mediaAsset): array
    {
        $originId = $mediaAsset->origin_media_asset_id ?? $mediaAsset->id;

        return DB::transaction(function () use ($originId): array {
            $origin = MediaAsset::query()
                ->lockForUpdate()
                ->find($originId);

            if ($origin === null) {
                return [
                    'media_asset_id' => $originId,
                    'detached' => true,
                    'already_missing' => true,
                    'removed_planned_selections' => 0,
                    'preserved_planned_selections' => 0,
                ];
            }

            $plannedSelections = MediaAsset::query()
                ->where('origin_media_asset_id', $origin->id)
                ->lockForUpdate()
                ->get();
            $preservedSelections = $plannedSelections
                ->filter($this->hasAvailableFullFile(...));
            $removedSelections = $plannedSelections
                ->whereNotIn('id', $preservedSelections->modelKeys());
            $removedSelectionCount = $removedSelections->count();
            $removedMedia = $removedSelections->push($origin);
            $paths = $this->mediaFileGarbageCollector->pathsFor($removedMedia);

            MediaAsset::query()
                ->whereKey($removedMedia->modelKeys())
                ->delete();

            DB::afterCommit(
                fn (): int => $this->mediaFileGarbageCollector->deleteUnreferenced($paths),
            );

            return [
                'media_asset_id' => $originId,
                'detached' => true,
                'removed_planned_selections' => $removedSelectionCount,
                'preserved_planned_selections' => $preservedSelections->count(),
            ];
        });
    }

    private function hasAvailableFullFile(MediaAsset $mediaAsset): bool
    {
        if (blank($mediaAsset->path)) {
            return false;
        }

        try {
            return Storage::disk($mediaAsset->disk)->exists($mediaAsset->path);
        } catch (Throwable) {
            return true;
        }
    }
}
