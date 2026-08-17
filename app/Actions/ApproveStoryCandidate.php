<?php

namespace App\Actions;

use App\CandidateStatus;
use App\ContentPlanStatus;
use App\Models\ContentPlan;
use App\Models\ModerationAction;
use App\Models\StoryCandidate;
use App\Models\User;
use App\ModerationActionType;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApproveStoryCandidate
{
    public function approve(StoryCandidate $candidate, User $user, ?string $reason = null): StoryCandidate
    {
        return $this->moderate($candidate, $user, CandidateStatus::Approved, ModerationActionType::ApproveCandidate, $reason);
    }

    public function reject(StoryCandidate $candidate, User $user, string $reason): StoryCandidate
    {
        return $this->moderate($candidate, $user, CandidateStatus::Rejected, ModerationActionType::RejectCandidate, $reason);
    }

    public function reserve(StoryCandidate $candidate, User $user, ?string $reason = null): StoryCandidate
    {
        return $this->moderate($candidate, $user, CandidateStatus::Reserve, ModerationActionType::ApproveCandidate, $reason);
    }

    public function approveAllPending(ContentPlan $contentPlan, User $user): int
    {
        return DB::transaction(function () use ($contentPlan, $user): int {
            $lockedPlan = ContentPlan::query()
                ->lockForUpdate()
                ->findOrFail($contentPlan->id);

            if ($lockedPlan->status !== ContentPlanStatus::CandidateReview) {
                throw ValidationException::withMessages([
                    'content_plan' => 'Массовое одобрение доступно только на этапе отбора новостей.',
                ]);
            }

            $pendingCandidates = $lockedPlan->storyCandidates()
                ->where('status', CandidateStatus::Pending)
                ->lockForUpdate()
                ->get();

            foreach ($pendingCandidates as $pendingCandidate) {
                $this->applyModeration(
                    $pendingCandidate,
                    $user,
                    CandidateStatus::Approved,
                    ModerationActionType::ApproveCandidate,
                    null,
                );
            }

            return $pendingCandidates->count();
        });
    }

    private function moderate(StoryCandidate $candidate, User $user, CandidateStatus $status, ModerationActionType $action, ?string $reason): StoryCandidate
    {
        return DB::transaction(function () use ($candidate, $user, $status, $action, $reason): StoryCandidate {
            $lockedCandidate = StoryCandidate::query()
                ->lockForUpdate()
                ->findOrFail($candidate->id);

            $this->applyModeration($lockedCandidate, $user, $status, $action, $reason);

            return $lockedCandidate->fresh();
        });
    }

    private function applyModeration(
        StoryCandidate $candidate,
        User $user,
        CandidateStatus $status,
        ModerationActionType $action,
        ?string $reason,
    ): void {
        $candidate->update([
            'status' => $status,
            'approved_by' => in_array($status, [CandidateStatus::Approved, CandidateStatus::Reserve], true) ? $user->id : null,
            'approved_at' => in_array($status, [CandidateStatus::Approved, CandidateStatus::Reserve], true) ? now() : null,
            'rejected_by' => $status === CandidateStatus::Rejected ? $user->id : null,
            'rejected_at' => $status === CandidateStatus::Rejected ? now() : null,
        ]);
        ModerationAction::create([
            'user_id' => $user->id,
            'subject_type' => $candidate::class,
            'subject_id' => $candidate->id,
            'action' => $action,
            'reason' => $reason,
        ]);
    }
}
