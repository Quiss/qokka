<?php

namespace App\Actions;

use App\MediaType;
use App\Models\MediaAsset;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class RequestMissingTelegramMedia
{
    public function __construct(
        private readonly RequestTelegramMediaDownload $requestMediaDownload,
    ) {}

    /**
     * @return array{requested: int, failed: int, skipped: bool}
     */
    public function handle(
        int $limit = 0,
        bool $throttled = false,
        bool $includeFailed = false,
    ): array {
        if (
            $throttled
            && ! Cache::store(
                (string) config('services.telegram.coordination_cache_store', 'redis'),
            )->add('telegram:missing-media-scan', now()->timestamp, now()->addMinutes(5))
        ) {
            return ['requested' => 0, 'failed' => 0, 'skipped' => true];
        }

        $selectedOriginIds = MediaAsset::query()
            ->whereNotNull('origin_media_asset_id')
            ->whereNull('path')
            ->pluck('origin_media_asset_id')
            ->unique();
        $query = MediaAsset::query()
            ->whereNull('origin_media_asset_id')
            ->whereNotNull('source_message_id')
            ->where(function ($query) use ($selectedOriginIds, $includeFailed): void {
                $query
                    ->where(function ($query) use ($includeFailed): void {
                        $query->whereNull('path')
                            ->whereIn('type', [
                                MediaType::Photo->value,
                                MediaType::Animation->value,
                                MediaType::Document->value,
                            ]);

                        if (! $includeFailed) {
                            $query->whereNull('failed_at');
                        }
                    })
                    ->orWhere(function ($query) use ($includeFailed): void {
                        $query->whereNull('preview_path')
                            ->where('type', MediaType::Video->value)
                            ->whereNotNull('metadata->thumbnail_type');

                        if (! $includeFailed) {
                            $query->whereNull('preview_failed_at');
                        }
                    });

                if ($selectedOriginIds->isNotEmpty()) {
                    $query->orWhere(function ($query) use ($selectedOriginIds, $includeFailed): void {
                        $query->whereNull('path')->whereKey($selectedOriginIds);

                        if (! $includeFailed) {
                            $query->whereNull('failed_at');
                        }
                    });
                }
            })
            ->with('sourceMessage.telegramAccount', 'sourceMessage.sourceChannel.telegramAccounts')
            ->orderBy('id');
        $limit = max(0, $limit);

        if ($limit > 0) {
            $query->limit($limit);
        }

        $requested = 0;
        $failed = 0;

        $query->get()->each(function (MediaAsset $asset) use (
            $selectedOriginIds,
            $includeFailed,
            &$requested,
            &$failed,
        ): void {
            $requests = [];

            if (
                blank($asset->path)
                && ($includeFailed || $asset->failed_at === null)
                && (
                    in_array($asset->type, [
                        MediaType::Photo,
                        MediaType::Animation,
                        MediaType::Document,
                    ], true)
                    || $selectedOriginIds->contains($asset->id)
                )
            ) {
                $requests[] = false;
            }

            if (
                $asset->type === MediaType::Video
                && blank($asset->preview_path)
                && ($includeFailed || $asset->preview_failed_at === null)
                && filled(data_get($asset->metadata, 'thumbnail_type'))
            ) {
                $requests[] = true;
            }

            foreach ($requests as $previewOnly) {
                try {
                    $this->requestMediaDownload->handle($asset, $previewOnly);
                    $requested++;
                } catch (Throwable $exception) {
                    $failed++;
                    Log::warning('Missing Telegram media could not be requested.', [
                        'media_asset_id' => $asset->id,
                        'preview_only' => $previewOnly,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
        });

        return ['requested' => $requested, 'failed' => $failed, 'skipped' => false];
    }
}
