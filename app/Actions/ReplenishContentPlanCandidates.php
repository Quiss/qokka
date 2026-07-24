<?php

namespace App\Actions;

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
            $activePosts = $lockedPlan->plannedPosts()
                ->where('status', '!=', PlannedPostStatus::Cancelled)
                ->count();
            $vacancies = max(0, count($lockedPlan->slot_schedule ?? []) - $activePosts);

            if ($vacancies === 0) {
                throw ValidationException::withMessages([
                    'content_plan' => 'В плане нет свободных слотов для добора.',
                ]);
            }

            if ($lockedPlan->status === ContentPlanStatus::Generating) {
                return false;
            }

            $reserveTarget = max(1, $lockedPlan->candidate_target - count($lockedPlan->slot_schedule ?? []));
            $lockedPlan->update([
                'status' => ContentPlanStatus::Generating,
                'failure_reason' => null,
                'failed_at' => null,
            ]);

            DB::afterCommit(
                fn () => ReplenishContentPlanCandidatesJob::dispatch(
                    $lockedPlan->id,
                    $vacancies + $reserveTarget,
                )->onQueue('ai'),
            );

            return true;
        });
    }
}
