<?php

namespace App\Services;

use App\MediaType;
use App\Models\MediaAsset;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class TelegramVideoPreparer
{
    public function prepare(MediaAsset $asset): MediaAsset
    {
        if ($asset->type !== MediaType::Video || blank($asset->path) || ! Storage::disk($asset->disk)->exists($asset->path)) {
            return $asset;
        }

        try {
            if (blank(data_get($asset->metadata, 'video_inspected_at'))) {
                $this->inspect($asset);
            }
        } catch (Throwable $exception) {
            $this->recordFailure($asset, 'video_inspection_error', $exception);
        }

        try {
            $this->createPreview($asset->fresh());
        } catch (Throwable $exception) {
            $this->recordFailure($asset->fresh(), 'preview_generation_error', $exception);
        }

        return $asset->fresh();
    }

    private function inspect(MediaAsset $asset): void
    {
        $result = Process::timeout(15)->run([
            'ffprobe',
            '-v',
            'error',
            '-select_streams',
            'v:0',
            '-show_streams',
            '-show_format',
            '-of',
            'json',
            Storage::disk($asset->disk)->path($asset->path),
        ]);

        if (! $result->successful()) {
            throw new RuntimeException('ffprobe failed: '.trim($result->errorOutput()));
        }

        $probe = json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);
        $stream = collect(is_array($probe['streams'] ?? null) ? $probe['streams'] : [])
            ->first(fn (mixed $candidate): bool => is_array($candidate) && ($candidate['codec_type'] ?? null) === 'video');

        if (! is_array($stream)) {
            throw new RuntimeException('ffprobe did not return a video stream.');
        }

        $rotation = $this->rotation($stream);
        $width = $this->positiveInteger($stream['width'] ?? null);
        $height = $this->positiveInteger($stream['height'] ?? null);

        if ($rotation !== null && abs($rotation) % 180 === 90) {
            [$width, $height] = [$height, $width];
        }

        $duration = $this->duration($stream['duration'] ?? data_get($probe, 'format.duration'));
        $codec = is_string($stream['codec_name'] ?? null) ? $stream['codec_name'] : null;
        $formatName = (string) data_get($probe, 'format.format_name', '');
        $metadata = array_merge(is_array($asset->metadata) ? $asset->metadata : [], array_filter([
            'width' => $width,
            'height' => $height,
            'duration' => $duration,
            'codec' => $codec,
            'rotation' => $rotation,
            'supports_streaming' => $codec === 'h264' && Str::contains($formatName, ['mp4', 'mov']),
            'video_inspected_at' => now()->toIso8601String(),
        ], fn (mixed $value): bool => $value !== null));
        unset($metadata['video_inspection_error']);

        $asset->update(['metadata' => $metadata]);
    }

    private function createPreview(MediaAsset $asset): void
    {
        $previewDisk = $asset->preview_disk ?: $asset->disk;

        if (filled($asset->preview_path) && $this->isUsablePreview($previewDisk, $asset->preview_path)) {
            return;
        }

        $relativePath = 'telegram/previews/'.now()->format('Y/m').'/'.Str::uuid().'.jpg';
        $absolutePath = Storage::disk('local')->path($relativePath);
        File::ensureDirectoryExists(dirname($absolutePath));
        $duration = max(0, (int) data_get($asset->metadata, 'duration', 0));
        $seek = number_format(min(1, $duration * 0.1), 2, '.', '');

        try {
            $result = Process::timeout(15)->run([
                'ffmpeg',
                '-y',
                '-ss',
                $seek,
                '-i',
                Storage::disk($asset->disk)->path($asset->path),
                '-frames:v',
                '1',
                '-vf',
                'scale=320:320:force_original_aspect_ratio=decrease',
                '-q:v',
                '5',
                $absolutePath,
            ]);

            if (! $result->successful()) {
                throw new RuntimeException('ffmpeg failed: '.trim($result->errorOutput()));
            }

            if (! File::exists($absolutePath) || File::size($absolutePath) === 0) {
                throw new RuntimeException('ffmpeg did not create a preview.');
            }

            if (File::size($absolutePath) > 200 * 1024) {
                throw new RuntimeException('Generated video preview exceeds 200 KB.');
            }

            $metadata = is_array($asset->metadata) ? $asset->metadata : [];
            unset($metadata['preview_generation_error']);
            $asset->update([
                'preview_disk' => 'local',
                'preview_path' => $relativePath,
                'preview_mime_type' => 'image/jpeg',
                'preview_downloaded_at' => now(),
                'preview_failed_at' => null,
                'metadata' => $metadata,
            ]);
        } catch (Throwable $exception) {
            if (File::exists($absolutePath)) {
                File::delete($absolutePath);
            }

            throw $exception;
        }
    }

    /** @param array<string, mixed> $stream */
    private function rotation(array $stream): ?int
    {
        $sideData = collect(is_array($stream['side_data_list'] ?? null) ? $stream['side_data_list'] : [])
            ->first(fn (mixed $value): bool => is_array($value) && is_numeric($value['rotation'] ?? null));
        $rotation = is_array($sideData) ? $sideData['rotation'] : data_get($stream, 'tags.rotate');

        return is_numeric($rotation) ? (int) round((float) $rotation) : null;
    }

    private function positiveInteger(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function duration(mixed $value): ?int
    {
        return is_numeric($value) && (float) $value >= 0 ? (int) round((float) $value) : null;
    }

    private function isUsablePreview(string $disk, string $path): bool
    {
        try {
            return Storage::disk($disk)->exists($path)
                && Storage::disk($disk)->size($path) <= 200 * 1024;
        } catch (Throwable) {
            return false;
        }
    }

    private function recordFailure(MediaAsset $asset, string $key, Throwable $exception): void
    {
        $metadata = array_merge(is_array($asset->metadata) ? $asset->metadata : [], [$key => $exception->getMessage()]);
        $updates = ['metadata' => $metadata];

        if ($key === 'preview_generation_error') {
            $updates['preview_failed_at'] = now();
        }

        $asset->update($updates);

        Log::warning('Telegram video preparation failed; the original video will still be published.', [
            'media_asset_id' => $asset->id,
            'stage' => $key,
            'error' => $exception->getMessage(),
        ]);
    }
}
