<?php

namespace App\Services;

use App\Models\MediaAsset;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class MediaFileGarbageCollector
{
    /**
     * @param  Collection<int, MediaAsset>  $mediaAssets
     * @return list<array{disk: string, path: string}>
     */
    public function pathsFor(Collection $mediaAssets): array
    {
        return array_values($mediaAssets
            ->flatMap(fn (MediaAsset $mediaAsset): array => array_filter([
                ['disk' => $mediaAsset->disk, 'path' => $mediaAsset->path],
                ['disk' => $mediaAsset->preview_disk ?: $mediaAsset->disk, 'path' => $mediaAsset->preview_path],
            ], fn (array $file): bool => filled($file['path'])))
            ->unique(fn (array $file): string => $file['disk'].':'.$file['path'])
            ->values()
            ->all());
    }

    /**
     * @param  list<array{disk: string, path: string}>  $files
     */
    public function deleteUnreferenced(array $files): int
    {
        $deletedFiles = 0;

        foreach ($files as $file) {
            if ($this->isFileStillReferenced($file['disk'], $file['path'])) {
                continue;
            }

            try {
                if (Storage::disk($file['disk'])->delete($file['path'])) {
                    $deletedFiles++;
                }
            } catch (Throwable $exception) {
                Log::warning('Unable to delete an unreferenced media file.', [
                    'disk' => $file['disk'],
                    'path' => $file['path'],
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $deletedFiles;
    }

    private function isFileStillReferenced(string $disk, string $path): bool
    {
        return MediaAsset::query()
            ->where(function (Builder $query) use ($disk, $path): void {
                $query
                    ->where(fn (Builder $query): Builder => $query->where('disk', $disk)->where('path', $path))
                    ->orWhere(function (Builder $query) use ($disk, $path): void {
                        $query
                            ->where('preview_path', $path)
                            ->where(function (Builder $query) use ($disk): void {
                                $query
                                    ->where('preview_disk', $disk)
                                    ->orWhere(fn (Builder $query): Builder => $query
                                        ->whereNull('preview_disk')
                                        ->where('disk', $disk));
                            });
                    });
            })
            ->exists();
    }
}
