<?php

namespace App\Actions;

use App\ContentPlanStatus;
use App\Filament\Resources\ContentPlans\ContentPlanResource;
use App\Jobs\ReplenishContentPlanCandidatesJob;
use App\Models\ContentPlan;
use App\Models\Publication;
use App\OperationsNotificationTopic;
use App\PlannedPostStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class AdvanceContentPlanSafetyNet
{
    public function __construct(
        private readonly PopulateContentPlanSafetyNet $populateContentPlan,
        private readonly FinalizeContentPlanSafetyNet $finalizeContentPlan,
        private readonly QueueOperationsNotification $queueOperationsNotification,
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

        $contentPlan = ContentPlan::query()
            ->whereBelongsTo($publication)
            ->where('plan_date', $localNow->toDateString())
            ->first();

        if ($contentPlan === null) {
            return false;
        }

        $startResult = $this->start($contentPlan);

        if (! $startResult['may_advance']) {
            return false;
        }

        if ($startResult['started']) {
            $this->queueOperationsNotification->handle(
                OperationsNotificationTopic::ContentPlans,
                "План для «{$publication->name}» передан на автоматическую модерацию",
                ['Дата: '.$contentPlan->plan_date->format('d.m.Y')],
                ContentPlanResource::getUrl(
                    'edit',
                    ['record' => $contentPlan],
                    panel: 'admin',
                ),
            );
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
            return false;
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

    /** @return array{may_advance: bool, started: bool} */
    private function start(ContentPlan $contentPlan): array
    {
        return DB::transaction(function () use ($contentPlan): array {
            $lockedPlan = ContentPlan::query()
                ->lockForUpdate()
                ->findOrFail($contentPlan->id);

            if ($this->isTerminal($lockedPlan)) {
                return ['may_advance' => false, 'started' => false];
            }

            $started = $lockedPlan->safety_net_started_at === null;

            if ($started) {
                $lockedPlan->update(['safety_net_started_at' => now()]);
            }

            return ['may_advance' => true, 'started' => $started];
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
