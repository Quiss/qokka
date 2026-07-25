<?php

namespace App\Actions;

use App\CandidateStatus;
use App\ContentPlanStatus;
use App\Jobs\ReplenishContentPlanCandidatesJob;
use App\Models\ContentPlan;
use App\PlannedPostStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReplenishContentPlanCandidates
{
    public function handle(ContentPlan $contentPlan): bool
    {
        return DB::transaction(function () use ($contentPlan): bool {
            $lockedPlan = ContentPlan::query()->lockForUpdate()->findOrFail($contentPlan->id);

            if ($lockedPlan->status === ContentPlanStatus::Generating) {
                return false;
            }

            $completionStatus = $lockedPlan->status;
            $candidateTarget = match ($completionStatus) {
                ContentPlanStatus::CandidateReview => $this->candidateReviewDeficit($lockedPlan),
                ContentPlanStatus::NeedsCandidates => $this->vacantPlanTarget($lockedPlan),
                default => throw ValidationException::withMessages([
                    'content_plan' => 'На текущем этапе добор новостей недоступен.',
                ]),
            };

            $lockedPlan->update([
                'status' => ContentPlanStatus::Generating,
                'failure_reason' => null,
                'failed_at' => null,
            ]);

            DB::afterCommit(
                fn () => ReplenishContentPlanCandidatesJob::dispatch(
                    $lockedPlan->id,
                    $candidateTarget,
                    $completionStatus,
                )->onQueue('ai'),
            );

            return true;
        });
    }

    private function candidateReviewDeficit(ContentPlan $contentPlan): int
    {
        $activeCandidates = $contentPlan->storyCandidates()
            ->whereIn('status', [
                CandidateStatus::Pending,
                CandidateStatus::Approved,
                CandidateStatus::Reserve,
                CandidateStatus::Selected,
            ])
            ->count();
        $deficit = max(0, $contentPlan->candidate_target - $activeCandidates);

        if ($deficit === 0) {
            throw ValidationException::withMessages([
                'content_plan' => 'В подборке уже достаточно активных кандидатов.',
            ]);
        }

        return $deficit;
    }

    private function vacantPlanTarget(ContentPlan $contentPlan): int
    {
        $activePosts = $contentPlan->plannedPosts()
            ->where('status', '!=', PlannedPostStatus::Cancelled)
            ->count();
        $vacancies = max(0, count($contentPlan->slot_schedule ?? []) - $activePosts);

        if ($vacancies === 0) {
            throw ValidationException::withMessages([
                'content_plan' => 'В плане нет свободных слотов для добора.',
            ]);
        }

        $reserveTarget = max(1, $contentPlan->candidate_target - count($contentPlan->slot_schedule ?? []));

        return $vacancies + $reserveTarget;
    }
}
