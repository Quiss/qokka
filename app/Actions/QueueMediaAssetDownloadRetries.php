<?php

namespace App\Actions;

use App\Models\MediaAsset;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class QueueMediaAssetDownloadRetries
{
    public function __construct(
        private readonly RequestTelegramMediaDownload $requestMediaDownload,
    ) {}

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
                    try {
                        $this->requestMediaDownload->handle($origin);
                    } catch (Throwable $exception) {
                        $metadata = is_array($origin->metadata) ? $origin->metadata : [];
                        $origin->update([
                            'failed_at' => now(),
                            'metadata' => array_merge($metadata, [
                                'download_error' => $exception->getMessage(),
                            ]),
                        ]);
                        MediaAsset::query()
                            ->where('origin_media_asset_id', $origin->id)
                            ->update([
                                'failed_at' => $origin->failed_at,
                                'metadata' => $origin->metadata,
                            ]);
                        Log::warning('Telegram media retry could not create an owner command.', [
                            'media_asset_id' => $origin->id,
                            'error' => $exception->getMessage(),
                        ]);
                    }
                }
            });

            return $origins->count();
        });
    }
}
