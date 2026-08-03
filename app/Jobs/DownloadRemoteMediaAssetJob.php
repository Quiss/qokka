<?php

namespace App\Jobs;

use App\Models\MediaAsset;
use App\Models\SourcePost;
use App\Services\MediaFileGarbageCollector;
use App\Services\SourceUrlGuard;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class DownloadRemoteMediaAssetJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 90;

    /** @var list<int> */
    public array $backoff = [30, 120, 300];

    public function __construct(public readonly int $mediaAssetId) {}

    public function uniqueId(): string
    {
        return (string) $this->mediaAssetId;
    }

    public function handle(
        SourceUrlGuard $urlGuard,
        MediaFileGarbageCollector $mediaFileGarbageCollector,
    ): void {
        $mediaAsset = MediaAsset::query()
            ->whereNull('origin_media_asset_id')
            ->find($this->mediaAssetId);

        if ($mediaAsset === null || filled($mediaAsset->path)) {
            return;
        }

        $remoteUrl = data_get($mediaAsset->metadata, 'remote_url');

        if (! is_string($remoteUrl) || blank($remoteUrl)) {
            throw new RuntimeException('У удалённого медиа отсутствует URL загрузки.');
        }

        $urlGuard->ensurePublicHttps($remoteUrl);
        $response = Http::connectTimeout((int) config('channelbot.sources.connect_timeout', 5))
            ->timeout((int) config('channelbot.sources.media_timeout', 30))
            ->withOptions(['allow_redirects' => false])
            ->get($remoteUrl);

        if ($response->status() >= 300 && $response->status() < 400) {
            throw new RuntimeException('Редиректы при загрузке медиа запрещены.');
        }

        $response->throw();
        $mimeType = Str::before((string) $response->header('Content-Type'), ';');
        $extensions = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];

        if (! isset($extensions[$mimeType])) {
            throw new RuntimeException('Источник вернул неподдерживаемый тип изображения.');
        }

        $contents = $response->body();
        $size = strlen($contents);
        $maxBytes = (int) config('channelbot.sources.remote_media_max_bytes', 10 * 1024 * 1024);

        if ($size === 0 || $size > $maxBytes) {
            throw new RuntimeException('Размер удалённого изображения недопустим.');
        }

        $mediaAsset->loadMissing('mediable');
        $sourcePost = $mediaAsset->mediable;

        if (! $sourcePost instanceof SourcePost) {
            throw new RuntimeException('Удалённое медиа должно принадлежать исходному материалу.');
        }

        $disk = 'local';
        $path = sprintf(
            'source-media/%d/%d-%s.%s',
            $sourcePost->source_id,
            $sourcePost->id,
            hash('sha256', $remoteUrl),
            $extensions[$mimeType],
        );

        if (! Storage::disk($disk)->put($path, $contents)) {
            throw new RuntimeException('Не удалось сохранить удалённое изображение.');
        }

        $oldPaths = DB::transaction(function () use (
            $mediaAsset,
            $disk,
            $path,
            $mimeType,
            $size,
            $contents,
            $mediaFileGarbageCollector,
        ): array {
            $origin = MediaAsset::query()->lockForUpdate()->findOrFail($mediaAsset->id);
            $oldPaths = $mediaFileGarbageCollector->pathsFor(collect([$origin]));
            $metadata = Arr::except(is_array($origin->metadata) ? $origin->metadata : [], ['download_error']);
            $values = [
                'disk' => $disk,
                'path' => $path,
                'mime_type' => $mimeType,
                'size_bytes' => $size,
                'checksum' => hash('sha256', $contents),
                'metadata' => $metadata,
                'downloaded_at' => now(),
                'failed_at' => null,
            ];

            $origin->update($values);
            MediaAsset::query()
                ->where('origin_media_asset_id', $origin->id)
                ->update($values);

            return $oldPaths;
        });

        $mediaFileGarbageCollector->deleteUnreferenced($oldPaths);
    }

    public function failed(?Throwable $exception): void
    {
        $mediaAsset = MediaAsset::query()->find($this->mediaAssetId);

        if ($mediaAsset === null) {
            return;
        }

        $metadata = is_array($mediaAsset->metadata) ? $mediaAsset->metadata : [];
        $values = [
            'failed_at' => now(),
            'metadata' => array_merge($metadata, [
                'download_error' => Str::limit($exception?->getMessage() ?? 'Неизвестная ошибка загрузки.', 1000),
            ]),
        ];
        $mediaAsset->update($values);
        MediaAsset::query()
            ->where('origin_media_asset_id', $mediaAsset->id)
            ->update($values);
    }
}
