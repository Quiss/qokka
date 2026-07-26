<?php

namespace App\Actions;

use App\Jobs\DownloadMediaAssetJob;
use App\Models\MediaAsset;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class QueueMediaAssetDownloadRetries
{
    /** @param Collection<int, MediaAsset> $mediaAssets */
    public function handle(Collection $mediaAssets): int
    {
        $originIds = $mediaAssets
            ->filter(fn (MediaAsset $mediaAsset): bool => blank($mediaAsset->path))
            ->map(fn (MediaAsset $mediaAsset): int => $mediaAsset->origin_media_asset_id ?? $mediaAsset->id)
            ->unique()
            ->values();

        if ($originIds->isEmpty()) {
            return 0;
        }

        return DB::transaction(function () use ($originIds): int {
            $origins = MediaAsset::query()
                ->whereKey($originIds)
                ->lockForUpdate()
                ->get();

            foreach ($origins as $origin) {
                $metadata = Arr::except(
                    is_array($origin->metadata) ? $origin->metadata : [],
                    ['download_error'],
                );
                $origin->update([
                    'failed_at' => null,
                    'metadata' => $metadata,
                ]);
                MediaAsset::query()
                    ->where('origin_media_asset_id', $origin->id)
                    ->update([
                        'failed_at' => null,
                        'metadata' => $metadata,
                    ]);
            }

            DB::afterCommit(function () use ($origins): void {
                foreach ($origins as $origin) {
                    DownloadMediaAssetJob::dispatch($origin->id)->onQueue('telegram');
                }
            });

            return $origins->count();
        });
    }
}
