<?php

namespace App\Actions;

use App\CandidateStatus;
use App\ContentPlanStatus;
use App\DeliveryStatus;
use App\Jobs\RewritePlannedPostJob;
use App\Models\ContentPlan;
use App\Models\ModerationAction;
use App\Models\PlannedPost;
use App\Models\StoryCandidate;
use App\Models\User;
use App\ModerationActionType;
use App\PlannedPostStatus;
use App\Services\PlannedPostMediaManager;
use Illuminate\Support\Facades\DB;

class ReplaceRejectedPlannedPost
{
    public function __construct(private readonly PlannedPostMediaManager $mediaManager) {}

    public function handle(PlannedPost $plannedPost, User $user, string $reason): ?PlannedPost
    {
        return DB::transaction(function () use ($plannedPost, $user, $reason): ?PlannedPost {
            $lockedPost = PlannedPost::query()
                ->with('storyCandidate')
                ->lockForUpdate()
                ->findOrFail($plannedPost->id);
            $contentPlan = ContentPlan::query()->lockForUpdate()->findOrFail($lockedPost->content_plan_id);

            if ($lockedPost->status === PlannedPostStatus::Cancelled) {
                return null;
            }

            $lockedPost->update([
                'status' => PlannedPostStatus::Cancelled,
                'approved_by' => null,
                'approved_at' => null,
                'rewrite_generation' => $lockedPost->rewrite_generation + 1,
            ]);
            $lockedPost->deliveries()
                ->where('status', '!=', DeliveryStatus::Published)
                ->update(['status' => DeliveryStatus::Cancelled]);
            $lockedPost->storyCandidate->update([
                'status' => CandidateStatus::Rejected,
                'approved_by' => null,
                'approved_at' => null,
                'rejected_by' => $user->id,
                'rejected_at' => now(),
            ]);
            ModerationAction::create([
                'user_id' => $user->id,
                'subject_type' => $lockedPost::class,
                'subject_id' => $lockedPost->id,
                'action' => ModerationActionType::RejectPost,
                'reason' => $reason,
            ]);

            $reserve = StoryCandidate::query()
                ->whereBelongsTo($contentPlan)
                ->where('status', CandidateStatus::Reserve)
                ->orderByDesc('score')
                ->lockForUpdate()
                ->first();

            if ($reserve === null) {
                $contentPlan->update([
                    'status' => ContentPlanStatus::NeedsCandidates,
                    'ready_at' => null,
                ]);

                return null;
            }

            $reserve->update(['status' => CandidateStatus::Selected]);
            $replacement = $contentPlan->plannedPosts()->create([
                'story_candidate_id' => $reserve->id,
                'replaces_planned_post_id' => $lockedPost->id,
                'scheduled_at' => $lockedPost->scheduled_at,
                'status' => PlannedPostStatus::Rewriting,
            ]);
            $this->mediaManager->copyDefaultSelection($replacement, $reserve);
            $contentPlan->update([
                'status' => ContentPlanStatus::Rewriting,
                'ready_at' => null,
                'failure_reason' => null,
                'failed_at' => null,
            ]);
            ModerationAction::create([
                'user_id' => $user->id,
                'subject_type' => $replacement::class,
                'subject_id' => $replacement->id,
                'action' => ModerationActionType::ReplacePostFromReserve,
                'reason' => $reason,
                'metadata' => [
                    'replaced_planned_post_id' => $lockedPost->id,
                    'reserve_candidate_id' => $reserve->id,
                ],
            ]);

            DB::afterCommit(
                fn () => RewritePlannedPostJob::dispatch(
                    $replacement->id,
                    $replacement->rewrite_generation,
                    focusedReview: true,
                )->onQueue('ai'),
            );

            return $replacement->fresh(['storyCandidate', 'mediaAssets']);
        });
    }
}
