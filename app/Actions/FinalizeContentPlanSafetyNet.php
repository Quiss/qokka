<?php

namespace App\Actions;

use App\ContentPlanStatus;
use App\Models\ContentPlan;
use App\Models\ModerationAction;
use App\Models\PlannedPost;
use App\ModerationActionType;
use App\PlannedPostStatus;
use App\Services\PlannedPostMediaManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinalizeContentPlanSafetyNet
{
    public function __construct(
        private readonly ApprovePlannedPost $approvePlannedPost,
        private readonly PlannedPostMediaManager $mediaManager,
    ) {}

    public function handle(ContentPlan $contentPlan): void
    {
        $contentPlan->load([
            'publication.destination',
            'plannedPosts.mediaAssets',
        ]);

        if (in_array($contentPlan->status, [
            ContentPlanStatus::Ready,
            ContentPlanStatus::Active,
            ContentPlanStatus::Completed,
            ContentPlanStatus::Skipped,
            ContentPlanStatus::Failed,
        ], true)) {
            return;
        }

        foreach ($contentPlan->plannedPosts as $plannedPost) {
            if ($this->mustBeSkipped($plannedPost)) {
                $this->skip($plannedPost, 'ai_review_blocked');
            }
        }

        $contentPlan->refresh()->load([
            'publication.destination',
            'plannedPosts.mediaAssets',
        ]);
        $automaticallyApprovable = $contentPlan->plannedPosts
            ->filter(fn (PlannedPost $plannedPost): bool => $this->canBeAutomaticallyApproved($plannedPost));

        if ($automaticallyApprovable->isNotEmpty() && ! $contentPlan->publication->destination?->is_active) {
            $contentPlan->update([
                'status' => ContentPlanStatus::Failed,
                'failure_reason' => 'Активный канал назначения для страховочной автопубликации не настроен.',
                'failed_at' => now(),
            ]);

            return;
        }

        foreach ($automaticallyApprovable as $plannedPost) {
            $this->mediaManager->syncAvailableOrigins($plannedPost);

            if ($this->mediaManager->hasFailedSelection($plannedPost)) {
                $this->skip($plannedPost, 'media_preparation_failed');

                continue;
            }

            if ($this->mediaManager->hasUnpreparedSelection($plannedPost)) {
                continue;
            }

            try {
                $this->approvePlannedPost->approveAutomatically($plannedPost);
            } catch (ValidationException) {
                $plannedPost->refresh();

                if ($plannedPost->status === PlannedPostStatus::NeedsReschedule) {
                    $this->skip($plannedPost, 'no_future_publication_slot');
                }
            }
        }
    }

    public function expire(ContentPlan $contentPlan): void
    {
        $contentPlan->plannedPosts()
            ->whereIn('status', [
                PlannedPostStatus::Rewriting,
                PlannedPostStatus::FinalReview,
                PlannedPostStatus::Blocked,
                PlannedPostStatus::NeedsReschedule,
            ])
            ->get()
            ->each(fn (PlannedPost $plannedPost) => $this->skip($plannedPost, 'publication_window_ended'));
    }

    private function mustBeSkipped(PlannedPost $plannedPost): bool
    {
        if ($plannedPost->status === PlannedPostStatus::Blocked) {
            return true;
        }

        return in_array($plannedPost->status, [
            PlannedPostStatus::FinalReview,
            PlannedPostStatus::NeedsReschedule,
        ], true) && (
            $plannedPost->ai_review_status === 'blocked'
            || array_values(array_filter($plannedPost->risk_flags ?? [])) !== []
        );
    }

    private function canBeAutomaticallyApproved(PlannedPost $plannedPost): bool
    {
        return in_array($plannedPost->status, [
            PlannedPostStatus::FinalReview,
            PlannedPostStatus::NeedsReschedule,
        ], true)
            && $plannedPost->ai_review_status === 'passed'
            && array_values(array_filter($plannedPost->risk_flags ?? [])) === [];
    }

    private function skip(PlannedPost $plannedPost, string $reason): void
    {
        DB::transaction(function () use ($plannedPost, $reason): void {
            $lockedPost = PlannedPost::query()
                ->lockForUpdate()
                ->findOrFail($plannedPost->id);

            if (in_array($lockedPost->status, [
                PlannedPostStatus::Approved,
                PlannedPostStatus::Publishing,
                PlannedPostStatus::Published,
                PlannedPostStatus::Cancelled,
            ], true)) {
                return;
            }

            $lockedPost->update(['status' => PlannedPostStatus::Cancelled]);
            ModerationAction::create([
                'user_id' => null,
                'subject_type' => $lockedPost::class,
                'subject_id' => $lockedPost->id,
                'action' => ModerationActionType::SafetyNetSkipPost,
                'reason' => $reason,
                'metadata' => [
                    'safety_net' => true,
                    'risk_flags' => $lockedPost->risk_flags ?? [],
                ],
            ]);
        });
    }
}
