<?php

namespace App\Services;

use App\Contracts\MadelineClient;
use App\Contracts\TelegramMediaClient;
use App\Exceptions\PermanentTelegramMediaException;
use App\MediaType;
use App\Models\MediaAsset;
use App\Models\TelegramOwnerCommand;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class TelegramOwnerMediaDownloader
{
    /**
     * @return array{media_asset_id: int, path: string, size_bytes: int}
     */
    public function handle(
        TelegramOwnerCommand $command,
        MadelineClient $client,
        bool $previewOnly,
    ): array {
        $mediaAssetId = (int) ($command->payload['media_asset_id'] ?? 0);
        $asset = MediaAsset::query()
            ->with('originMediaAsset.sourceMessage.sourceChannel', 'sourceMessage.sourceChannel')
            ->find($mediaAssetId);

        if ($asset === null) {
            throw new PermanentTelegramMediaException("Медиа {$mediaAssetId} не найдено.");
        }

        $origin = $asset->originMediaAsset ?? $asset;
        $existingPath = $previewOnly ? $origin->preview_path : $origin->path;
        $existingDisk = $previewOnly ? $origin->preview_disk : $origin->disk;

        if (
            filled($existingPath)
            && filled($existingDisk)
            && Storage::disk((string) $existingDisk)->exists((string) $existingPath)
        ) {
            $this->syncClones($origin);

            return [
                'media_asset_id' => $origin->id,
                'path' => (string) $existingPath,
                'size_bytes' => (int) ($origin->size_bytes ?? 0),
            ];
        }

        if (filled($existingPath)) {
            $origin->update($previewOnly
                ? [
                    'preview_path' => null,
                    'preview_downloaded_at' => null,
                ]
                : [
                    'path' => null,
                    'downloaded_at' => null,
                    'checksum' => null,
                ]);
        }

        $sourceMessage = $origin->sourceMessage;
        $sourceChannel = $sourceMessage?->sourceChannel;

        if ($sourceMessage === null || $sourceChannel === null) {
            throw new PermanentTelegramMediaException(
                "Медиа {$origin->id} не связано с исходным Telegram-сообщением и каналом.",
            );
        }

        $freshMessage = $client->getChannelMessage(
            $sourceChannel->telegram_peer_id ?? $sourceChannel->telegramReference(),
            $sourceMessage->external_message_id,
        );

        if ($freshMessage === null || ! is_array($freshMessage['media'] ?? null)) {
            throw new PermanentTelegramMediaException(
                "Исходное Telegram-сообщение {$sourceMessage->external_message_id} больше не содержит медиа.",
            );
        }

        $extension = $previewOnly ? 'jpg' : $this->extension($origin);
        $relativePath = 'telegram/'.($previewOnly ? 'previews' : 'media')
            .'/'.now()->format('Y/m').'/'.Str::uuid().'.'.$extension;
        $absolutePath = Storage::disk('local')->path($relativePath);
        $temporaryPath = Storage::disk('local')->path('telegram/tmp/'.Str::uuid().'.part');
        File::ensureDirectoryExists(dirname($absolutePath));
        File::ensureDirectoryExists(dirname($temporaryPath));

        try {
            if ($client instanceof TelegramMediaClient) {
                $client->downloadMessageToFile(
                    $sourceChannel->telegram_peer_id ?? $sourceChannel->telegramReference(),
                    $sourceMessage->external_message_id,
                    $temporaryPath,
                    $previewOnly,
                );
            } else {
                $client->downloadToFile(
                    $this->downloadReference($origin, $freshMessage, $previewOnly),
                    $temporaryPath,
                );
            }
            $downloadedSize = $this->assertDownloadedFile($temporaryPath, $previewOnly);

            if (! File::move($temporaryPath, $absolutePath)) {
                throw new RuntimeException('Не удалось атомарно переместить загруженное медиа.');
            }

            $checksum = hash_file('sha256', $absolutePath);

            if ($checksum === false) {
                throw new RuntimeException('Не удалось вычислить контрольную сумму загруженного медиа.');
            }

            $metadata = Arr::except(
                is_array($origin->metadata) ? $origin->metadata : [],
                [$previewOnly ? 'preview_download_error' : 'download_error'],
            );

            DB::transaction(function () use (
                $origin,
                $previewOnly,
                $relativePath,
                $downloadedSize,
                $checksum,
                $metadata,
            ): void {
                if ($previewOnly) {
                    $origin->update([
                        'preview_disk' => 'local',
                        'preview_path' => $relativePath,
                        'preview_mime_type' => 'image/jpeg',
                        'preview_downloaded_at' => now(),
                        'preview_failed_at' => null,
                        'metadata' => $metadata,
                    ]);
                } else {
                    $origin->update([
                        'disk' => 'local',
                        'path' => $relativePath,
                        'size_bytes' => $downloadedSize,
                        'checksum' => $checksum,
                        'downloaded_at' => now(),
                        'failed_at' => null,
                        'metadata' => $metadata,
                    ]);
                }

                $this->syncClones($origin->fresh());
            });

            return [
                'media_asset_id' => $origin->id,
                'path' => $relativePath,
                'size_bytes' => $downloadedSize,
            ];
        } catch (Throwable $exception) {
            $this->deleteIfExists($absolutePath);

            throw $exception;
        } finally {
            $this->deleteIfExists($temporaryPath);
            $this->deleteIfExists($temporaryPath.'.lock');
        }
    }

    public function recordFailure(
        TelegramOwnerCommand $command,
        Throwable $exception,
        bool $previewOnly,
    ): void {
        $asset = MediaAsset::query()
            ->with('originMediaAsset')
            ->find((int) ($command->payload['media_asset_id'] ?? 0));

        if ($asset === null) {
            return;
        }

        $origin = $asset->originMediaAsset ?? $asset;
        $metadata = is_array($origin->metadata) ? $origin->metadata : [];
        $origin->update($previewOnly
            ? [
                'preview_failed_at' => now(),
                'metadata' => array_merge($metadata, [
                    'preview_download_error' => $exception->getMessage(),
                ]),
            ]
            : [
                'failed_at' => now(),
                'metadata' => array_merge($metadata, [
                    'download_error' => $exception->getMessage(),
                ]),
            ]);
        $this->syncClones($origin->fresh());
    }

    /** @param array<string, mixed> $rawMessage */
    private function downloadReference(
        MediaAsset $asset,
        array $rawMessage,
        bool $previewOnly,
    ): mixed {
        if (! $previewOnly) {
            return $rawMessage;
        }

        $document = data_get($rawMessage, 'media.document');
        $thumbnailType = data_get($asset->metadata, 'thumbnail_type');

        if (! is_array($document) || ! is_string($thumbnailType) || $thumbnailType === '') {
            throw new PermanentTelegramMediaException(
                "Telegram не предоставил превью для медиа {$asset->id}.",
            );
        }

        $thumbnails = is_array($document['thumbs'] ?? null) ? $document['thumbs'] : [];
        $thumbnail = collect($thumbnails)->firstWhere('type', $thumbnailType);
        $thumbnailSize = is_array($thumbnail)
            ? (int) ($thumbnail['size'] ?? 0)
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
        $filenameExtension = pathinfo(
            (string) data_get($asset->metadata, 'file_name'),
            PATHINFO_EXTENSION,
        );

        if ($filenameExtension !== '') {
            return $filenameExtension;
        }

        $telegramExtension = ltrim(
            (string) data_get($asset->metadata, 'file_extension'),
            '.',
        );

        if ($telegramExtension !== '') {
            return $telegramExtension;
        }

        return match ($asset->mime_type) {
            'image/jpeg' => 'jpg',
            'image/gif' => 'gif',
            'image/png' => 'png',
            'video/mp4' => 'mp4',
            default => $asset->type === MediaType::Photo ? 'jpg' : 'bin',
        };
    }

    private function assertDownloadedFile(string $path, bool $previewOnly): int
    {
        $size = File::size($path);

        if ($size <= 0) {
            throw new RuntimeException('Telegram вернул пустой файл.');
        }

        if (! $previewOnly && $size > (int) config('services.telegram.media_max_bytes')) {
            throw new PermanentTelegramMediaException(
                'Медиа превышает настроенный лимит размера.',
            );
        }

        return $size;
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
                'size_bytes' => $origin->size_bytes,
                'checksum' => $origin->checksum,
                'downloaded_at' => $origin->downloaded_at,
                'preview_downloaded_at' => $origin->preview_downloaded_at,
                'failed_at' => $origin->failed_at,
                'preview_failed_at' => $origin->preview_failed_at,
                'metadata' => $origin->metadata,
            ]);
    }

    private function deleteIfExists(string $path): void
    {
        try {
            if (File::exists($path)) {
                File::delete($path);
            }
        } catch (Throwable $exception) {
            Log::warning('Telegram media file cleanup failed.', [
                'path' => $path,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
