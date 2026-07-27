<?php

namespace App\Actions;

use App\Models\MediaAsset;
use App\Models\PlannedPost;
use App\PlannedPostStatus;
use App\Services\MediaFileGarbageCollector;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PruneOldPlannedPostMedia
{
    private const CHUNK_SIZE = 500;

    public function __construct(
        private readonly MediaFileGarbageCollector $mediaFileGarbageCollector,
    ) {}

    /**
     * @return array{posts: int, media: int, files: int}
     */
    public function handle(?int $retentionDays = null): array
    {
        $retentionDays ??= (int) config('channelbot.content.retention_days', 14);

        if ($retentionDays < 1) {
            throw new InvalidArgumentException('Planned post media retention must be at least one day.');
        }

        $cutoff = now()->subDays($retentionDays);
        $totals = ['posts' => 0, 'media' => 0, 'files' => 0];

        PlannedPost::query()
            ->whereHas('mediaAssets')
            ->where(fn (Builder $query): Builder => $this->eligibleForPruning($query, $cutoff))
            ->select('id')
            ->chunkById(self::CHUNK_SIZE, function (Collection $plannedPosts) use (&$totals): void {
                $mediaAssets = MediaAsset::query()
                    ->where('mediable_type', PlannedPost::class)
                    ->whereIn('mediable_id', $plannedPosts->modelKeys())
                    ->select(['id', 'mediable_id', 'disk', 'path', 'preview_disk', 'preview_path'])
                    ->get();
                $paths = $this->mediaFileGarbageCollector->pathsFor($mediaAssets);

                DB::transaction(
                    fn (): int => MediaAsset::query()
                        ->whereKey($mediaAssets->modelKeys())
                        ->delete(),
                );

                $totals['posts'] += $mediaAssets->pluck('mediable_id')->unique()->count();
                $totals['media'] += $mediaAssets->count();
                $totals['files'] += $this->mediaFileGarbageCollector->deleteUnreferenced($paths);
            });

        return $totals;
    }

    /**
     * @param  Builder<PlannedPost>  $query
     * @return Builder<PlannedPost>
     */
    private function eligibleForPruning(Builder $query, CarbonInterface $cutoff): Builder
    {
        return $query
            ->where(function (Builder $query) use ($cutoff): void {
                $query
                    ->where('status', PlannedPostStatus::Published)
                    ->where('published_at', '<', $cutoff);
            })
            ->orWhere(function (Builder $query) use ($cutoff): void {
                $query
                    ->where('status', PlannedPostStatus::Cancelled)
                    ->where('scheduled_at', '<', $cutoff);
            });
    }
}
