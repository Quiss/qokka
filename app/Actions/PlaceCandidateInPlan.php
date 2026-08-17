<?php

namespace App\Actions;

use App\CandidateStatus;
use App\ContentPlanStatus;
use App\Jobs\RewritePlannedPostJob;
use App\Models\ContentPlan;
use App\Models\ModerationAction;
use App\Models\PlannedPost;
use App\Models\StoryCandidate;
use App\Models\User;
use App\ModerationActionType;
use App\PlannedPostStatus;
use App\Services\PlannedPostMediaManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PlaceCandidateInPlan
{
    public function __construct(private readonly PlannedPostMediaManager $mediaManager) {}

    public function handle(StoryCandidate $candidate, User $user): PlannedPost
    {
        return DB::transaction(function () use ($candidate, $user): PlannedPost {
            $lockedCandidate = StoryCandidate::query()->lockForUpdate()->findOrFail($candidate->id);
            $contentPlan = ContentPlan::query()->lockForUpdate()->findOrFail($lockedCandidate->content_plan_id);

            if (! in_array($lockedCandidate->status, [CandidateStatus::Pending, CandidateStatus::Approved, CandidateStatus::Reserve], true)) {
                throw ValidationException::withMessages(['candidate' => 'Этот кандидат уже обработан.']);
            }

            $occupiedSlots = $contentPlan->plannedPosts()
                ->where('status', '!=', PlannedPostStatus::Cancelled)
                ->pluck('scheduled_at')
                ->filter()
                ->map(fn ($date): string => $date->toIso8601String());
            $vacantSlot = collect($contentPlan->slot_schedule ?? [])
                ->map(fn (string $slot): CarbonImmutable => CarbonImmutable::parse($slot))
                ->first(fn (CarbonImmutable $slot): bool => ! $occupiedSlots->contains($slot->toIso8601String()));

            if ($vacantSlot === null) {
                throw ValidationException::withMessages(['candidate' => 'В плане нет свободного слота.']);
            }

            $lockedCandidate->update([
                'status' => CandidateStatus::Selected,
                'approved_by' => $user->id,
                'approved_at' => now(),
                'rejected_by' => null,
                'rejected_at' => null,
            ]);
            $plannedPost = $contentPlan->plannedPosts()->create([
                'story_candidate_id' => $lockedCandidate->id,
                'scheduled_at' => $vacantSlot,
                'status' => PlannedPostStatus::Rewriting,
            ]);
            $this->mediaManager->copyDefaultSelection($plannedPost, $lockedCandidate);
            $contentPlan->update([
                'status' => ContentPlanStatus::Rewriting,
                'failure_reason' => null,
                'failed_at' => null,
            ]);
            ModerationAction::create([
                'user_id' => $user->id,
                'subject_type' => $plannedPost::class,
                'subject_id' => $plannedPost->id,
                'action' => ModerationActionType::PlaceCandidateInPlan,
                'metadata' => ['story_candidate_id' => $lockedCandidate->id],
            ]);

            DB::afterCommit(
                fn () => RewritePlannedPostJob::dispatch(
                    $plannedPost->id,
                    $plannedPost->rewrite_generation,
                    focusedReview: true,
                )->onQueue('ai'),
            );

            return $plannedPost->fresh();
        });
    }
}
