<?php

namespace App\Actions;

use App\Models\MediaAsset;
use App\Models\SourcePost;
use App\Services\MediaFileGarbageCollector;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PruneOldSourcePosts
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
            throw new InvalidArgumentException('Source post retention must be at least one day.');
        }

        $cutoff = now()->subDays($retentionDays);
        $totals = ['posts' => 0, 'media' => 0, 'files' => 0];

        SourcePost::query()
            ->where('posted_at', '<', $cutoff)
            ->select('id')
            ->chunkById(self::CHUNK_SIZE, function (Collection $sourcePosts) use ($cutoff, &$totals): void {
                $sourcePostIds = $sourcePosts->modelKeys();

                $result = DB::transaction(function () use ($sourcePostIds, $cutoff): array {
                    $mediaAssets = MediaAsset::query()
                        ->where('mediable_type', SourcePost::class)
                        ->whereIn('mediable_id', $sourcePostIds)
                        ->select(['id', 'disk', 'path', 'preview_disk', 'preview_path'])
                        ->get();
                    $paths = $this->mediaFileGarbageCollector->pathsFor($mediaAssets);

                    MediaAsset::query()->whereKey($mediaAssets->modelKeys())->delete();
                    $deletedPosts = SourcePost::query()
                        ->whereKey($sourcePostIds)
                        ->where('posted_at', '<', $cutoff)
                        ->delete();

                    return [
                        'posts' => $deletedPosts,
                        'media' => $mediaAssets->count(),
                        'paths' => $paths,
                    ];
                });

                $totals['posts'] += $result['posts'];
                $totals['media'] += $result['media'];
                $totals['files'] += $this->mediaFileGarbageCollector->deleteUnreferenced($result['paths']);
            });

        return $totals;
    }
}
