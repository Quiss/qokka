<?php

namespace App\Jobs;

use App\Actions\RequestTelegramMediaDownload;
use App\Models\MediaAsset;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * @deprecated Kept for one deployment cycle so legacy serialized jobs can be retired safely.
 */
class DownloadMediaAssetJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly int $mediaAssetId,
        public readonly bool $previewOnly = false,
    ) {}

    public function handle(RequestTelegramMediaDownload $requestMediaDownload): void
    {
        $mediaAsset = MediaAsset::query()->find($this->mediaAssetId);

        if ($mediaAsset !== null) {
            $requestMediaDownload->handle($mediaAsset, $this->previewOnly);
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Legacy Telegram media request job failed.', [
            'media_asset_id' => $this->mediaAssetId,
            'preview_only' => $this->previewOnly,
            'error' => $exception?->getMessage(),
        ]);
    }
}
