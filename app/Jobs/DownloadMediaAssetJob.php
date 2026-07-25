<?php

namespace App\Jobs;

use App\MediaType;
use App\Models\MediaAsset;
use App\Services\MadelineClientPool;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class DownloadMediaAssetJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 360;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public readonly int $mediaAssetId,
        public readonly bool $previewOnly = false,
    ) {}

    public function uniqueId(): string
    {
        return $this->mediaAssetId.':'.($this->previewOnly ? 'preview' : 'full');
    }

    public function handle(MadelineClientPool $clientPool): void
    {
        $asset = MediaAsset::query()
            ->with('originMediaAsset.sourceMessage.sourceChannel.collectorTelegramAccount', 'sourceMessage.sourceChannel.collectorTelegramAccount')
            ->find($this->mediaAssetId);

        if ($asset === null) {
            return;
        }

        $origin = $asset->originMediaAsset ?? $asset;

        if ($this->previewOnly && filled($origin->preview_path)) {
            $this->syncClones($origin);

            return;
        }

        if (! $this->previewOnly && filled($origin->path)) {
            $this->syncClones($origin);

            return;
        }

        $sourceMessage = $origin->sourceMessage;
        $account = $sourceMessage?->sourceChannel->collectorTelegramAccount;

        if ($sourceMessage === null || $account === null || ! $account->is_active) {
            throw new RuntimeException('Для скачивания медиа не найден активный Telegram-аккаунт источника.');
        }

        if (! $this->previewOnly && ($origin->size_bytes ?? 0) > (int) config('services.telegram.media_max_bytes')) {
            throw new RuntimeException('Медиа превышает лимит Telegram 50 МБ.');
        }

        $rawPayload = is_array($sourceMessage->raw_payload) ? $sourceMessage->raw_payload : [];
        $reference = $this->downloadReference($origin, $rawPayload);
        $extension = $this->previewOnly ? 'jpg' : $this->extension($origin);
        $relativePath = 'telegram/'.($this->previewOnly ? 'previews' : 'media').'/'.now()->format('Y/m').'/'.Str::uuid().'.'.$extension;
        $absolutePath = Storage::disk('local')->path($relativePath);
        File::ensureDirectoryExists(dirname($absolutePath));

        try {
            $clientPool->forAccount($account)->downloadToFile($reference, $absolutePath);
        } catch (Throwable $exception) {
            $clientPool->forget($account);

            throw $exception;
        }

        if ($this->previewOnly) {
            $origin->update([
                'preview_disk' => 'local',
                'preview_path' => $relativePath,
                'preview_mime_type' => 'image/jpeg',
                'preview_downloaded_at' => now(),
                'preview_failed_at' => null,
            ]);
        } else {
            $origin->update([
                'disk' => 'local',
                'path' => $relativePath,
                'checksum' => hash_file('sha256', $absolutePath),
                'downloaded_at' => now(),
                'failed_at' => null,
            ]);
        }

        $this->syncClones($origin->fresh());
    }

    public function failed(?Throwable $exception): void
    {
        $asset = MediaAsset::query()->with('originMediaAsset')->find($this->mediaAssetId);
        $origin = $asset->originMediaAsset ?? $asset;

        if ($origin === null) {
            return;
        }

        $metadata = is_array($origin->metadata) ? $origin->metadata : [];
        $origin->update($this->previewOnly
            ? [
                'preview_failed_at' => now(),
                'metadata' => array_merge($metadata, ['preview_download_error' => $exception?->getMessage()]),
            ]
            : [
                'failed_at' => now(),
                'metadata' => array_merge($metadata, ['download_error' => $exception?->getMessage()]),
            ]);
        $this->syncClones($origin->fresh());
    }

    /** @param array<string, mixed> $rawMessage */
    private function downloadReference(MediaAsset $asset, array $rawMessage): mixed
    {
        if (! $this->previewOnly) {
            $botApiFileId = data_get($asset->metadata, 'bot_api_file_id');

            return is_string($botApiFileId) && $botApiFileId !== '' ? $botApiFileId : $rawMessage;
        }

        $document = data_get($rawMessage, 'media.document');
        $thumbnailType = data_get($asset->metadata, 'thumbnail_type');

        if (! is_array($document) || ! is_string($thumbnailType) || $thumbnailType === '') {
            throw new RuntimeException('Telegram не предоставил превью для этого видео.');
        }

        $thumbnails = is_array($document['thumbs'] ?? null) ? $document['thumbs'] : [];
        $thumbnail = collect($thumbnails)->firstWhere('type', $thumbnailType);
        $thumbnailSizes = is_array($thumbnail) && is_array($thumbnail['sizes'] ?? null)
            ? $thumbnail['sizes']
            : [];
        $thumbnailSize = is_array($thumbnail)
            ? (int) ($thumbnail['size'] ?? collect($thumbnailSizes)->last() ?? 0)
            : 0;

        return [
            'InputFileLocation' => [
                '_' => 'inputDocumentFileLocation',
                'id' => $document['id'],
                'access_hash' => $document['access_hash'],
                'version' => $document['version'] ?? 0,
                'dc_id' => $document['dc_id'],
                'file_reference' => $document['file_reference'],
                'thumb_size' => $thumbnailType,
            ],
            'size' => $thumbnailSize,
            'name' => 'preview',
            'ext' => '.jpg',
            'mime' => 'image/jpeg',
        ];
    }

    private function extension(MediaAsset $asset): string
    {
        $filename = (string) data_get($asset->metadata, 'file_name');
        $extension = pathinfo($filename, PATHINFO_EXTENSION);

        if ($extension !== '') {
            return $extension;
        }

        return match ($asset->mime_type) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'video/mp4' => 'mp4',
            default => $asset->type === MediaType::Photo ? 'jpg' : 'bin',
        };
    }

    private function syncClones(MediaAsset $origin): void
    {
        MediaAsset::query()
            ->where('origin_media_asset_id', $origin->id)
            ->update([
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
    }
}
