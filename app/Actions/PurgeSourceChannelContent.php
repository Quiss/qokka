<?php

namespace App\Actions;

use App\Models\MediaAsset;
use App\Models\Source;
use App\Models\SourcePost;
use App\Services\MediaFileGarbageCollector;
use Illuminate\Support\Facades\DB;

class PurgeSourceChannelContent
{
    public function __construct(
        private readonly MediaFileGarbageCollector $mediaFileGarbageCollector,
    ) {}

    /**
     * @return array{posts: int, messages: int, media: int, candidate_links: int, files: int}
     */
    public function handle(Source $source): array
    {
        $result = DB::transaction(function () use ($source): array {
            Source::query()
                ->whereKey($source->id)
                ->lockForUpdate()
                ->firstOrFail();

            $postQuery = SourcePost::query()->whereBelongsTo($source);
            $postIds = $postQuery->pluck('id');
            $messages = $source->messages()->count();
            $candidateLinks = DB::table('source_post_story_candidate')
                ->whereIn('source_post_id', $postIds)
                ->count();
            $assets = MediaAsset::query()
                ->where('mediable_type', SourcePost::class)
                ->whereIn('mediable_id', $postIds)
                ->select(['id', 'disk', 'path', 'preview_disk', 'preview_path'])
                ->get();
            $paths = $this->mediaFileGarbageCollector->pathsFor($assets);

            MediaAsset::query()->whereKey($assets->modelKeys())->delete();
            $posts = $postQuery->delete();
            $metadata = is_array($source->metadata) ? $source->metadata : [];
            unset($metadata['statistics_sync']);
            $source->update([
                'last_event_at' => null,
                'last_backfilled_at' => null,
                'metadata' => $metadata,
            ]);

            return [
                'posts' => $posts,
                'messages' => $messages,
                'media' => $assets->count(),
                'candidate_links' => $candidateLinks,
                'paths' => $paths,
            ];
        });

        $deletedFiles = $this->mediaFileGarbageCollector->deleteUnreferenced($result['paths']);

        return [
            'posts' => $result['posts'],
            'messages' => $result['messages'],
            'media' => $result['media'],
            'candidate_links' => $result['candidate_links'],
            'files' => $deletedFiles,
        ];
    }
}
