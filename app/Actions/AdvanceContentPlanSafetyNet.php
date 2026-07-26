<?php

namespace App\Actions;

use App\ContentPlanStatus;
use App\Jobs\ReplenishContentPlanCandidatesJob;
use App\Models\ContentPlan;
use App\Models\Publication;
use App\PlannedPostStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class AdvanceContentPlanSafetyNet
{
    public function __construct(
        private readonly QueueContentPlanGeneration $queueContentPlanGeneration,
        private readonly PopulateContentPlanSafetyNet $populateContentPlan,
        private readonly FinalizeContentPlanSafetyNet $finalizeContentPlan,
    ) {}

    public function handle(Publication $publication): bool
    {
        $localNow = CarbonImmutable::now($publication->timezone);
        $cutoff = CarbonImmutable::parse(
            $localNow->toDateString().' '.$publication->safety_net_cutoff_time,
            $publication->timezone,
        );

        if ($localNow->isBefore($cutoff)) {
            return false;
        }

        $contentPlan = ContentPlan::query()->firstOrCreate([
            'publication_id' => $publication->id,
            'plan_date' => $localNow->toDateString(),
        ]);

        if (! $this->start($contentPlan)) {
            return false;
        }

        $windowEnd = CarbonImmutable::parse(
            $localNow->toDateString().' '.$publication->publish_window_end,
            $publication->timezone,
        );

        if ($localNow->isAfter($windowEnd)) {
            $this->finalizeContentPlan->expire($contentPlan);
            $this->complete($contentPlan);

            return true;
        }

        $contentPlan->refresh();

        if ($contentPlan->generated_at === null && $contentPlan->plannedPosts()->doesntExist()) {
            return $this->queueContentPlanGeneration->handle($contentPlan);
        }

        if (in_array($contentPlan->status, [
            ContentPlanStatus::Generating,
            ContentPlanStatus::Rewriting,
            ContentPlanStatus::Failed,
        ], true)) {
            return false;
        }

        $this->finalizeContentPlan->handle($contentPlan);
        $contentPlan->refresh();

        if ($this->isTerminal($contentPlan)) {
            return true;
        }

        if ($this->hasUnfinishedPosts($contentPlan)) {
            return false;
        }

        $vacantSlots = $this->populateContentPlan->futureVacantSlotCount($contentPlan);
        $safeCandidates = $this->populateContentPlan->safeCandidateCount($contentPlan);

        if (
            $vacantSlots > $safeCandidates
            && $contentPlan->safety_net_refreshed_at === null
            && $this->queueCandidateRefresh($contentPlan, $vacantSlots - $safeCandidates)
        ) {
            return true;
        }

        if ($vacantSlots > 0 && $safeCandidates > 0) {
            return $this->populateContentPlan->handle($contentPlan) > 0;
        }

        $this->complete($contentPlan);

        return true;
    }

    private function start(ContentPlan $contentPlan): bool
    {
        return DB::transaction(function () use ($contentPlan): bool {
            $lockedPlan = ContentPlan::query()
                ->lockForUpdate()
                ->findOrFail($contentPlan->id);

            if ($this->isTerminal($lockedPlan)) {
                return false;
            }

            if ($lockedPlan->safety_net_started_at === null) {
                $lockedPlan->update(['safety_net_started_at' => now()]);
            }

            return true;
        });
    }

    private function queueCandidateRefresh(ContentPlan $contentPlan, int $candidateTarget): bool
    {
        return DB::transaction(function () use ($contentPlan, $candidateTarget): bool {
            $lockedPlan = ContentPlan::query()
                ->lockForUpdate()
                ->findOrFail($contentPlan->id);

            if (
                $lockedPlan->safety_net_refreshed_at !== null
                || $lockedPlan->status === ContentPlanStatus::Generating
            ) {
                return false;
            }

            $completionStatus = $lockedPlan->status;
            $lockedPlan->update([
                'status' => ContentPlanStatus::Generating,
                'safety_net_refreshed_at' => now(),
                'failure_reason' => null,
                'failed_at' => null,
            ]);

            DB::afterCommit(
                fn () => ReplenishContentPlanCandidatesJob::dispatch(
                    $lockedPlan->id,
                    max(1, $candidateTarget),
                    $completionStatus,
                    false,
                )->onQueue('ai'),
            );

            return true;
        });
    }

    private function complete(ContentPlan $contentPlan): void
    {
        DB::transaction(function () use ($contentPlan): void {
            $lockedPlan = ContentPlan::query()
                ->lockForUpdate()
                ->findOrFail($contentPlan->id);

            if ($this->isTerminal($lockedPlan) || $this->hasUnfinishedPosts($lockedPlan)) {
                return;
            }

            $hasPublishablePosts = $lockedPlan->plannedPosts()
                ->whereIn('status', [
                    PlannedPostStatus::Approved,
                    PlannedPostStatus::Publishing,
                    PlannedPostStatus::Published,
                ])
                ->exists();
            $lockedPlan->update([
                'status' => $hasPublishablePosts
                    ? ContentPlanStatus::Ready
                    : ContentPlanStatus::Skipped,
                'ready_at' => $hasPublishablePosts ? now() : null,
                'safety_net_completed_at' => now(),
                'failure_reason' => null,
                'failed_at' => null,
            ]);
        });
    }

    private function hasUnfinishedPosts(ContentPlan $contentPlan): bool
    {
        return $contentPlan->plannedPosts()
            ->whereIn('status', [
                PlannedPostStatus::Rewriting,
                PlannedPostStatus::FinalReview,
                PlannedPostStatus::Blocked,
                PlannedPostStatus::Failed,
                PlannedPostStatus::NeedsReschedule,
            ])
            ->exists();
    }

    private function isTerminal(ContentPlan $contentPlan): bool
    {
        return in_array($contentPlan->status, [
            ContentPlanStatus::Ready,
            ContentPlanStatus::Active,
            ContentPlanStatus::Completed,
            ContentPlanStatus::Skipped,
            ContentPlanStatus::Failed,
        ], true);
    }
}
