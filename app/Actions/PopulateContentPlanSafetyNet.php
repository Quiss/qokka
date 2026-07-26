<?php

namespace App\Actions;

use App\CandidateStatus;
use App\ContentPlanStatus;
use App\Jobs\RewritePlannedPostJob;
use App\Models\ContentPlan;
use App\Models\ModerationAction;
use App\Models\PlannedPost;
use App\Models\StoryCandidate;
use App\ModerationActionType;
use App\PlannedPostStatus;
use App\Services\PlannedPostMediaManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PopulateContentPlanSafetyNet
{
    public function __construct(private readonly PlannedPostMediaManager $mediaManager) {}

    public function handle(ContentPlan $contentPlan): int
    {
        return DB::transaction(function () use ($contentPlan): int {
            $lockedPlan = ContentPlan::query()
                ->with('publication')
                ->lockForUpdate()
                ->findOrFail($contentPlan->id);

            if (
                $lockedPlan->safety_net_started_at === null
                || in_array($lockedPlan->status, [
                    ContentPlanStatus::Ready,
                    ContentPlanStatus::Active,
                    ContentPlanStatus::Completed,
                    ContentPlanStatus::Skipped,
                    ContentPlanStatus::Failed,
                ], true)
            ) {
                return 0;
            }

            $availableSlots = $this->futureVacantSlots($lockedPlan);

            if ($availableSlots->isEmpty()) {
                return 0;
            }

            $candidates = $this->safeCandidates($lockedPlan)
                ->take($availableSlots->count())
                ->values();
            $plannedPostIds = [];

            foreach ($candidates as $index => $candidate) {
                $previousStatus = $candidate->status;
                $candidate->update([
                    'status' => CandidateStatus::Selected,
                    'approved_at' => $candidate->approved_at ?? now(),
                    'rejected_by' => null,
                    'rejected_at' => null,
                ]);
                $plannedPost = $lockedPlan->plannedPosts()->create([
                    'story_candidate_id' => $candidate->id,
                    'scheduled_at' => $availableSlots[$index],
                    'status' => PlannedPostStatus::Rewriting,
                ]);
                $this->mediaManager->copyDefaultSelection($plannedPost, $candidate);
                $this->recordAutomation($candidate, ModerationActionType::SafetyNetSelectCandidate, [
                    'previous_status' => $previousStatus->value,
                ]);
                $this->recordAutomation($plannedPost, ModerationActionType::SafetyNetPlaceCandidate, [
                    'story_candidate_id' => $candidate->id,
                    'scheduled_at' => $availableSlots[$index]->toIso8601String(),
                ]);
                $plannedPostIds[] = $plannedPost->id;
            }

            if ($plannedPostIds === []) {
                return 0;
            }

            $lockedPlan->update([
                'status' => ContentPlanStatus::Rewriting,
                'failure_reason' => null,
                'failed_at' => null,
            ]);

            DB::afterCommit(function () use ($plannedPostIds): void {
                foreach ($plannedPostIds as $plannedPostId) {
                    RewritePlannedPostJob::dispatch(
                        $plannedPostId,
                        focusedReview: true,
                    )->onQueue('ai');
                }
            });

            return count($plannedPostIds);
        });
    }

    public function futureVacantSlotCount(ContentPlan $contentPlan): int
    {
        return $this->futureVacantSlots($contentPlan)->count();
    }

    public function safeCandidateCount(ContentPlan $contentPlan): int
    {
        return $this->safeCandidates($contentPlan)->count();
    }

    /** @return Collection<int, CarbonImmutable> */
    private function futureVacantSlots(ContentPlan $contentPlan): Collection
    {
        $occupiedSlots = $contentPlan->plannedPosts()
            ->where('status', '!=', PlannedPostStatus::Cancelled)
            ->pluck('scheduled_at')
            ->filter()
            ->map(fn (mixed $scheduledAt): string => CarbonImmutable::parse($scheduledAt)->toIso8601String());

        return collect($contentPlan->slot_schedule ?? [])
            ->map(fn (string $slot): CarbonImmutable => CarbonImmutable::parse($slot))
            ->filter(fn (CarbonImmutable $slot): bool => $slot->isFuture()
                && ! $occupiedSlots->contains($slot->toIso8601String()))
            ->values();
    }

    /** @return Collection<int, StoryCandidate> */
    private function safeCandidates(ContentPlan $contentPlan): Collection
    {
        return $contentPlan->storyCandidates()
            ->with('sourcePosts.mediaAssets')
            ->whereIn('status', [
                CandidateStatus::Approved,
                CandidateStatus::Pending,
                CandidateStatus::Reserve,
            ])
            ->whereDoesntHave('plannedPost')
            ->get()
            ->filter(fn (StoryCandidate $candidate): bool => array_values(array_filter($candidate->risk_flags ?? [])) === []
                && $candidate->sourcePosts->isNotEmpty())
            ->sort(function (StoryCandidate $first, StoryCandidate $second): int {
                return [
                    $this->candidatePriority($first->status),
                    -((float) $first->score),
                    $first->id,
                ] <=> [
                    $this->candidatePriority($second->status),
                    -((float) $second->score),
                    $second->id,
                ];
            })
            ->values();
    }

    private function candidatePriority(CandidateStatus $status): int
    {
        return match ($status) {
            CandidateStatus::Approved => 0,
            CandidateStatus::Pending => 1,
            CandidateStatus::Reserve => 2,
            CandidateStatus::Selected => 3,
            CandidateStatus::Rejected => 4,
        };
    }

    /** @param array<string, mixed> $metadata */
    private function recordAutomation(
        StoryCandidate|PlannedPost $subject,
        ModerationActionType $action,
        array $metadata,
    ): void {
        ModerationAction::create([
            'user_id' => null,
            'subject_type' => $subject::class,
            'subject_id' => $subject->id,
            'action' => $action,
            'metadata' => ['safety_net' => true, ...$metadata],
        ]);
    }
}
