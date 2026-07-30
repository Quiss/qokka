<?php

namespace App\Jobs;

use Amp\TimeoutCancellation;
use Amp\TimeoutException;
use App\Exceptions\PermanentTelegramMediaException;
use App\MediaType;
use App\Models\MediaAsset;
use App\Models\SourceChannel;
use App\Models\SourceMessage;
use App\Models\TelegramAccount;
use App\Services\MadelineClientPool;
use App\Services\MediaFileGarbageCollector;
use App\Services\TelegramMediaDownloadAccountResolver;
use App\Services\TelegramMediaDownloadConcurrency;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\FailOnException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class DownloadMediaAssetJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    private const int JOB_OVERHEAD_SECONDS = 45;

    public const string HIGH_PRIORITY_QUEUE = 'media-download-high';

    public const string BACKGROUND_QUEUE = 'media-download-low';

    public int $tries = 1;

    public int $timeout = 330;

    public int $uniqueFor = 7200;

    public function __construct(
        public readonly int $mediaAssetId,
        public readonly bool $previewOnly = false,
    ) {}

    /** @return list<FailOnException> */
    public function middleware(): array
    {
        return [new FailOnException([Throwable::class])];
    }

    public function uniqueVia(): Repository
    {
        return Cache::store(
            (string) config('services.telegram.coordination_cache_store', 'redis'),
        );
    }

    public function uniqueId(): string
    {
        return $this->mediaAssetId.':'.($this->previewOnly ? 'preview' : 'full');
    }

    public function handle(
        MadelineClientPool $clientPool,
        MediaFileGarbageCollector $mediaFileGarbageCollector,
        TelegramMediaDownloadAccountResolver $accountResolver,
        TelegramMediaDownloadConcurrency $downloadConcurrency,
    ): void {
        $asset = MediaAsset::query()
            ->with(
                'originMediaAsset.sourceMessage.telegramAccount',
                'originMediaAsset.sourceMessage.sourceChannel.collectorTelegramAccount',
                'originMediaAsset.sourceMessage.sourceChannel.telegramAccounts',
                'sourceMessage.telegramAccount',
                'sourceMessage.sourceChannel.collectorTelegramAccount',
                'sourceMessage.sourceChannel.telegramAccounts',
            )
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

        if ($sourceMessage === null) {
            throw new PermanentTelegramMediaException(
                "Медиа {$origin->id} не связано с исходным Telegram-сообщением.",
            );
        }

        $sourceChannel = $sourceMessage->sourceChannel;

        if ($sourceChannel === null) {
            throw new PermanentTelegramMediaException(
                "Исходное Telegram-сообщение {$sourceMessage->id} не связано с каналом.",
            );
        }

        $account = $accountResolver->resolve($sourceMessage);

        if ($account === null) {
            VerifySourceChannelAccessJob::dispatch($sourceChannel->id)->onQueue('telegram');
            $message = 'Для конкретного источника нет готового Telegram-аккаунта с подтверждённым доступом.';
            $this->rememberFailure(
                $origin,
                'telegram_account_unavailable',
                $message,
                $sourceChannel,
            );

            throw new RuntimeException($message);
        }

        $lockAcquired = $downloadConcurrency->run(
            $account,
            function () use (
                $origin,
                $sourceMessage,
                $sourceChannel,
                $account,
                $clientPool,
                $mediaFileGarbageCollector,
            ): void {
                $this->download(
                    $origin,
                    $sourceMessage,
                    $sourceChannel,
                    $account,
                    $clientPool,
                    $mediaFileGarbageCollector,
                );
            },
        );

        if (! $lockAcquired) {
            $message = 'Другой media download уже использует этот Telegram-аккаунт.';
            $this->rememberFailure(
                $origin,
                'telegram_account_busy',
                $message,
                $sourceChannel,
                $account,
            );

            throw new RuntimeException($message);
        }
    }

    private function download(
        MediaAsset $origin,
        SourceMessage $sourceMessage,
        SourceChannel $sourceChannel,
        TelegramAccount $account,
        MadelineClientPool $clientPool,
        MediaFileGarbageCollector $mediaFileGarbageCollector,
    ): void {
        $extension = $this->previewOnly ? 'jpg' : $this->extension($origin);
        $relativePath = 'telegram/'.($this->previewOnly ? 'previews' : 'media').'/'.now()->format('Y/m').'/'.Str::uuid().'.'.$extension;
        $absolutePath = Storage::disk('local')->path($relativePath);
        $temporaryPath = Storage::disk('local')->path('telegram/tmp/'.Str::uuid().'.part');
        File::ensureDirectoryExists(dirname($absolutePath));
        File::ensureDirectoryExists(dirname($temporaryPath));
        $startedAt = hrtime(true);
        $clientStartedAt = hrtime(true);
        $getMessageStartedAt = null;
        $downloadStartedAt = null;
        $operationTimeoutSeconds = $this->operationTimeoutSeconds();
        $logContext = [
            'media_asset_id' => $origin->id,
            'source_message_id' => $sourceMessage->id,
            'source_channel_id' => $sourceChannel->id,
            'telegram_account_id' => $account->id,
            'preview_only' => $this->previewOnly,
            'attempt' => $this->attempts(),
        ];

        try {
            Log::info('Telegram media download started.', $logContext + [
                'operation_timeout_seconds' => $operationTimeoutSeconds,
            ]);

            $client = $clientPool->forAccount($account);
            $cancellation = new TimeoutCancellation(
                $operationTimeoutSeconds,
                "Превышен лимит {$operationTimeoutSeconds} сек. на получение сообщения и скачивание медиа через MadelineProto IPC.",
            );
            Log::info('Telegram media IPC client is ready.', $logContext + [
                'client_connect_ms' => $this->elapsedMilliseconds($clientStartedAt),
            ]);

            $getMessageStartedAt = hrtime(true);
            $freshMessage = $client->getChannelMessage(
                $sourceChannel->telegram_peer_id ?? $sourceChannel->telegramReference(),
                $sourceMessage->external_message_id,
                $cancellation,
            );
            Log::info('Telegram media source message fetched.', $logContext + [
                'get_message_ms' => $this->elapsedMilliseconds($getMessageStartedAt),
            ]);

            if ($freshMessage === null || ! is_array($freshMessage['media'] ?? null)) {
                $this->discardUnavailableMedia($origin, $mediaFileGarbageCollector);

                return;
            }

            $downloadStartedAt = hrtime(true);
            Log::info('Telegram media file transfer started.', $logContext + [
                'expected_bytes' => $origin->size_bytes,
            ]);
            $client->downloadToFile(
                $this->downloadReference($origin, $freshMessage),
                $temporaryPath,
                $cancellation,
            );
            $downloadedSize = $this->assertDownloadedFileIsComplete($origin, $temporaryPath);

            if (! File::move($temporaryPath, $absolutePath)) {
                throw new RuntimeException('Не удалось переместить загруженное медиа из временного файла.');
            }

            $metadata = Arr::except(
                is_array($origin->metadata) ? $origin->metadata : [],
                $this->errorMetadataKeys(),
            );

            DB::transaction(function () use ($origin, $relativePath, $absolutePath, $downloadedSize, $metadata): void {
                if ($this->previewOnly) {
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
                        'checksum' => hash_file('sha256', $absolutePath),
                        'downloaded_at' => now(),
                        'failed_at' => null,
                        'metadata' => $metadata,
                    ]);
                }

                $this->syncClones($origin->fresh());
            });

            Log::info('Telegram media download completed.', [
                ...$logContext,
                'get_message_ms' => $this->elapsedMilliseconds($getMessageStartedAt, $downloadStartedAt),
                'download_ms' => $this->elapsedMilliseconds($downloadStartedAt),
                'total_ms' => $this->elapsedMilliseconds($startedAt),
                'downloaded_bytes' => $downloadedSize,
            ]);
        } catch (Throwable $exception) {
            $exception = $this->reportableException($exception);
            $this->deleteFilesWithoutFailingJob([$absolutePath]);
            $clientPool->forget($account);
            $this->rememberFailure(
                $origin,
                class_basename($exception),
                $exception->getMessage(),
                $sourceChannel,
                $account,
            );

            throw $exception;
        } finally {
            $this->deleteFilesWithoutFailingJob([$temporaryPath, $temporaryPath.'.lock']);
        }
    }

    private function operationTimeoutSeconds(): float
    {
        $configuredTimeout = (float) config(
            'services.telegram.media_operation_timeout_seconds',
            285,
        );
        $maximumTimeout = max(0.001, $this->timeout - self::JOB_OVERHEAD_SECONDS);

        return min(max(0.001, $configuredTimeout), $maximumTimeout);
    }

    private function reportableException(Throwable $exception): Throwable
    {
        $cause = $exception;

        do {
            if ($cause instanceof TimeoutException) {
                return new RuntimeException($cause->getMessage(), previous: $exception);
            }

            $cause = $cause->getPrevious();
        } while ($cause !== null);

        return $exception;
    }

    public function failed(?Throwable $exception): void
    {
        $asset = MediaAsset::query()->with('originMediaAsset')->find($this->mediaAssetId);
        $origin = $asset->originMediaAsset ?? $asset;

        if ($origin === null) {
            return;
        }

        if (
            ($this->previewOnly && filled($origin->preview_path))
            || (! $this->previewOnly && filled($origin->path))
        ) {
            $this->syncClones($origin);

            return;
        }

        $metadata = is_array($origin->metadata) ? $origin->metadata : [];
        $error = data_get($metadata, $this->lastErrorKey().'.message');

        if (! is_string($error) || $error === '') {
            $error = $exception?->getMessage();
        }

        $origin->update($this->previewOnly
            ? [
                'preview_failed_at' => now(),
                'metadata' => array_merge($metadata, ['preview_download_error' => $error]),
            ]
            : [
                'failed_at' => now(),
                'metadata' => array_merge($metadata, ['download_error' => $error]),
            ]);
        $this->syncClones($origin->fresh());

        Log::error('Telegram media download failed.', [
            'media_asset_id' => $origin->id,
            'source_message_id' => $origin->source_message_id,
            'preview_only' => $this->previewOnly,
            'error' => $error,
            'queue_exception' => $exception?->getMessage(),
        ]);
    }

    /** @return list<string> */
    private function errorMetadataKeys(): array
    {
        return $this->previewOnly
            ? ['preview_download_error', 'preview_download_last_error']
            : ['download_error', 'download_last_error'];
    }

    private function lastErrorKey(): string
    {
        return $this->previewOnly ? 'preview_download_last_error' : 'download_last_error';
    }

    private function rememberFailure(
        MediaAsset $origin,
        string $code,
        string $message,
        SourceChannel $sourceChannel,
        ?TelegramAccount $account = null,
    ): void {
        $metadata = is_array($origin->metadata) ? $origin->metadata : [];
        $metadata[$this->lastErrorKey()] = [
            'code' => $code,
            'message' => $message,
            'source_channel_id' => $sourceChannel->id,
            'telegram_account_id' => $account?->id,
            'attempt' => $this->attempts(),
            'recorded_at' => now()->toIso8601String(),
        ];
        $origin->update(['metadata' => $metadata]);
        $this->syncClones($origin->fresh());

        Log::warning('Telegram media download attempt failed.', [
            'media_asset_id' => $origin->id,
            'source_message_id' => $origin->source_message_id,
            'source_channel_id' => $sourceChannel->id,
            'telegram_account_id' => $account?->id,
            'preview_only' => $this->previewOnly,
            'attempt' => $this->attempts(),
            'error_code' => $code,
            'error' => $message,
        ]);
    }

    private function elapsedMilliseconds(?int $startedAt, ?int $finishedAt = null): ?float
    {
        if ($startedAt === null) {
            return null;
        }

        return round((($finishedAt ?? hrtime(true)) - $startedAt) / 1_000_000, 2);
    }

    /** @param array<string, mixed> $rawMessage */
    private function downloadReference(MediaAsset $asset, array $rawMessage): mixed
    {
        if (! $this->previewOnly) {
            return $rawMessage;
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

        $metadataExtension = (string) data_get($asset->metadata, 'file_extension');

        if ($metadataExtension !== '') {
            return ltrim($metadataExtension, '.');
        }

        return match ($asset->mime_type) {
            'image/jpeg' => 'jpg',
            'image/gif' => 'gif',
            'image/png' => 'png',
            'video/mp4' => 'mp4',
            default => $asset->type === MediaType::Photo ? 'jpg' : 'bin',
        };
    }

    private function assertDownloadedFileIsComplete(MediaAsset $asset, string $path): int
    {
        $actualSize = File::size($path);

        if ($actualSize <= 0) {
            throw new RuntimeException('Telegram вернул пустой файл.');
        }

        if (! $this->previewOnly && $actualSize > (int) config('services.telegram.media_max_bytes')) {
            throw new RuntimeException('Медиа превышает лимит Telegram 50 МБ.');
        }

        return $actualSize;
    }

    /** @param list<string> $paths */
    private function deleteFilesWithoutFailingJob(array $paths): void
    {
        foreach ($paths as $path) {
            if (! File::exists($path)) {
                continue;
            }

            try {
                File::delete($path);
            } catch (Throwable $exception) {
                Log::warning('Telegram media temporary file cleanup failed.', [
                    'media_asset_id' => $this->mediaAssetId,
                    'path' => $path,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    private function discardUnavailableMedia(
        MediaAsset $origin,
        MediaFileGarbageCollector $mediaFileGarbageCollector,
    ): void {
        if ($this->previewOnly && filled($origin->path)) {
            $metadata = is_array($origin->metadata) ? $origin->metadata : [];
            $origin->update([
                'preview_failed_at' => now(),
                'metadata' => array_merge($metadata, [
                    'preview_download_error' => 'Исходное сообщение больше недоступно в Telegram.',
                ]),
            ]);
            $this->syncClones($origin->fresh());

            Log::notice('Telegram media preview is unavailable, keeping the downloaded media.', [
                'media_asset_id' => $origin->id,
                'source_message_id' => $origin->source_message_id,
            ]);

            return;
        }

        $result = DB::transaction(function () use ($origin, $mediaFileGarbageCollector): array {
            $lockedOrigin = MediaAsset::query()
                ->lockForUpdate()
                ->find($origin->id);

            if ($lockedOrigin === null) {
                return ['removed_assets' => 0, 'paths' => []];
            }

            $mediaAssets = MediaAsset::query()
                ->where(fn ($query) => $query
                    ->whereKey($lockedOrigin->id)
                    ->orWhere('origin_media_asset_id', $lockedOrigin->id))
                ->get(['id', 'disk', 'path', 'preview_disk', 'preview_path']);
            $paths = $mediaFileGarbageCollector->pathsFor($mediaAssets);

            MediaAsset::query()
                ->where('origin_media_asset_id', $lockedOrigin->id)
                ->delete();
            $lockedOrigin->delete();

            return [
                'removed_assets' => $mediaAssets->count(),
                'paths' => $paths,
            ];
        });
        $deletedFiles = $mediaFileGarbageCollector->deleteUnreferenced($result['paths']);

        Log::notice('Unavailable Telegram media was removed from publications.', [
            'media_asset_id' => $origin->id,
            'source_message_id' => $origin->source_message_id,
            'removed_assets' => $result['removed_assets'],
            'deleted_files' => $deletedFiles,
        ]);
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
}
