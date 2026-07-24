<?php

namespace App\Actions;

use App\CandidateStatus;
use App\ContentPlanStatus;
use App\Jobs\RewritePlannedPostJob;
use App\Models\ContentPlan;
use App\Services\PlannedPostMediaManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class BuildContentPlan
{
    public function __construct(private readonly PlannedPostMediaManager $mediaManager) {}

    public function handle(ContentPlan $contentPlan): ContentPlan
    {
        $contentPlanId = $contentPlan->id;

        DB::transaction(function () use ($contentPlanId): array {
            $lockedPlan = ContentPlan::query()
                ->with('storyCandidates.sourcePosts.mediaAssets')
                ->lockForUpdate()
                ->findOrFail($contentPlanId);

            if ($lockedPlan->plannedPosts()->exists()) {
                return [];
            }

            $slots = $lockedPlan->slot_schedule ?? [];
            $selected = $lockedPlan->storyCandidates
                ->where('status', CandidateStatus::Approved)
                ->sortByDesc('score')
                ->take(count($slots))
                ->values();

            if ($selected->count() < count($slots)) {
                throw ValidationException::withMessages([
                    'candidates' => 'Одобрено недостаточно кандидатов для заполнения всех слотов.',
                ]);
            }

            $ids = [];

            foreach ($selected as $index => $candidate) {
                $candidate->update(['status' => CandidateStatus::Selected]);
                $plannedPost = $lockedPlan->plannedPosts()->create([
                    'story_candidate_id' => $candidate->id,
                    'scheduled_at' => CarbonImmutable::parse($slots[$index]),
                ]);
                $primarySource = $candidate->sourcePosts->firstWhere('pivot.is_primary', true) ?? $candidate->sourcePosts->first();

                if ($primarySource === null) {
                    throw new LogicException('A selected candidate must have at least one source post.');
                }

                $this->mediaManager->copyDefaultSelection($plannedPost, $candidate);

                $ids[] = $plannedPost->id;
            }

            $lockedPlan->storyCandidates()
                ->where('status', CandidateStatus::Approved)
                ->update(['status' => CandidateStatus::Reserve]);
            $lockedPlan->update([
                'status' => ContentPlanStatus::Rewriting,
                'failure_reason' => null,
                'failed_at' => null,
            ]);

            DB::afterCommit(function () use ($ids): void {
                foreach ($ids as $plannedPostId) {
                    RewritePlannedPostJob::dispatch($plannedPostId)->onQueue('ai');
                }
            });

            return $ids;
        });

        return ContentPlan::query()
            ->with('plannedPosts')
            ->findOrFail($contentPlanId);
    }
}
