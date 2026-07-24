<?php

namespace App\Actions;

use App\CandidateStatus;
use App\Models\ModerationAction;
use App\Models\StoryCandidate;
use App\Models\User;
use App\ModerationActionType;
use Illuminate\Support\Facades\DB;

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

    private function moderate(StoryCandidate $candidate, User $user, CandidateStatus $status, ModerationActionType $action, ?string $reason): StoryCandidate
    {
        return DB::transaction(function () use ($candidate, $user, $status, $action, $reason): StoryCandidate {
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

            return $candidate->fresh();
        });
    }
}
