<?php

namespace App\Actions;

use App\Models\AiRun;
use App\Models\ContentPlan;
use App\Models\MediaAsset;
use App\Models\ModerationAction;
use App\Models\PlannedPost;
use App\Models\StoryCandidate;
use App\Services\MediaFileGarbageCollector;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class DeleteContentPlan
{
    public function __construct(
        private readonly MediaFileGarbageCollector $mediaFileGarbageCollector,
    ) {}

    public function handle(ContentPlan $contentPlan): bool
    {
        $result = DB::transaction(function () use ($contentPlan): array {
            $lockedPlan = ContentPlan::query()
                ->lockForUpdate()
                ->find($contentPlan->id);

            if ($lockedPlan === null) {
                return ['deleted' => false, 'paths' => []];
            }

            $storyCandidateIds = array_values($lockedPlan->storyCandidates()
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all());
            $plannedPostIds = array_values($lockedPlan->plannedPosts()
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all());
            $mediaAssets = MediaAsset::query()
                ->where('mediable_type', PlannedPost::class)
                ->whereIn('mediable_id', $plannedPostIds)
                ->select(['id', 'disk', 'path', 'preview_disk', 'preview_path'])
                ->get();
            $paths = $this->mediaFileGarbageCollector->pathsFor($mediaAssets);

            MediaAsset::query()->whereKey($mediaAssets->modelKeys())->delete();
            $this->deleteAiRuns($lockedPlan->id, $storyCandidateIds, $plannedPostIds);
            $this->deleteModerationActions($lockedPlan->id, $storyCandidateIds, $plannedPostIds);
            PlannedPost::query()->whereKey($plannedPostIds)->delete();
            StoryCandidate::query()->whereKey($storyCandidateIds)->delete();
            $lockedPlan->delete();

            return ['deleted' => true, 'paths' => $paths];
        });

        if (! $result['deleted']) {
            return false;
        }

        $this->mediaFileGarbageCollector->deleteUnreferenced($result['paths']);

        return true;
    }

    /**
     * @param  list<int>  $storyCandidateIds
     * @param  list<int>  $plannedPostIds
     */
    private function deleteAiRuns(int $contentPlanId, array $storyCandidateIds, array $plannedPostIds): void
    {
        AiRun::query()
            ->where(fn (Builder $query): Builder => $query
                ->where(fn (Builder $query): Builder => $query
                    ->where('subject_type', ContentPlan::class)
                    ->where('subject_id', $contentPlanId))
                ->orWhere(fn (Builder $query): Builder => $query
                    ->where('subject_type', StoryCandidate::class)
                    ->whereIn('subject_id', $storyCandidateIds))
                ->orWhere(fn (Builder $query): Builder => $query
                    ->where('subject_type', PlannedPost::class)
                    ->whereIn('subject_id', $plannedPostIds)))
            ->delete();
    }

    /**
     * @param  list<int>  $storyCandidateIds
     * @param  list<int>  $plannedPostIds
     */
    private function deleteModerationActions(int $contentPlanId, array $storyCandidateIds, array $plannedPostIds): void
    {
        ModerationAction::query()
            ->where(fn (Builder $query): Builder => $query
                ->where(fn (Builder $query): Builder => $query
                    ->where('subject_type', ContentPlan::class)
                    ->where('subject_id', $contentPlanId))
                ->orWhere(fn (Builder $query): Builder => $query
                    ->where('subject_type', StoryCandidate::class)
                    ->whereIn('subject_id', $storyCandidateIds))
                ->orWhere(fn (Builder $query): Builder => $query
                    ->where('subject_type', PlannedPost::class)
                    ->whereIn('subject_id', $plannedPostIds)))
            ->delete();
    }
}
