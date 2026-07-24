<?php

namespace App\Actions;

use App\ContentPlanStatus;
use App\DeliveryStatus;
use App\Jobs\RewritePlannedPostJob;
use App\Models\ModerationAction;
use App\Models\PlannedPost;
use App\Models\PlannedPostRevision;
use App\Models\User;
use App\ModerationActionType;
use App\PlannedPostStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RequestPlannedPostRewrite
{
    public function handle(PlannedPost $plannedPost, User $user, ?string $instruction = null): PlannedPost
    {
        return DB::transaction(function () use ($plannedPost, $user, $instruction): PlannedPost {
            $lockedPost = PlannedPost::query()->lockForUpdate()->findOrFail($plannedPost->id);

            if (in_array($lockedPost->status, [PlannedPostStatus::Publishing, PlannedPostStatus::Published, PlannedPostStatus::Cancelled], true)) {
                throw ValidationException::withMessages([
                    'instruction' => 'Опубликованную, публикуемую или отклонённую запись нельзя отправить на повторный рерайт.',
                ]);
            }

            if (filled($lockedPost->text) && ! $lockedPost->revisions()->exists()) {
                $lockedPost->revisions()->create([
                    'version' => 1,
                    'text' => $lockedPost->text,
                    'risk_flags' => $lockedPost->risk_flags ?? [],
                    'requested_by' => $user->id,
                ]);
            }

            $generation = $lockedPost->rewrite_generation + 1;
            $lockedPost->deliveries()
                ->whereIn('status', [DeliveryStatus::Pending, DeliveryStatus::RetryScheduled, DeliveryStatus::Failed])
                ->update(['status' => DeliveryStatus::Cancelled]);
            $lockedPost->update([
                'status' => PlannedPostStatus::Rewriting,
                'rewrite_generation' => $generation,
                'approved_by' => null,
                'approved_at' => null,
                'override_by' => null,
                'override_reason' => null,
                'ai_review_status' => null,
                'failure_reason' => null,
                'failed_at' => null,
            ]);
            $lockedPost->contentPlan()->update([
                'status' => ContentPlanStatus::Rewriting,
                'ready_at' => null,
                'failure_reason' => null,
                'failed_at' => null,
            ]);
            ModerationAction::create([
                'user_id' => $user->id,
                'subject_type' => $lockedPost::class,
                'subject_id' => $lockedPost->id,
                'action' => ModerationActionType::RewritePost,
                'reason' => $instruction,
                'metadata' => ['rewrite_generation' => $generation],
            ]);

            DB::afterCommit(
                fn () => RewritePlannedPostJob::dispatch(
                    $lockedPost->id,
                    $generation,
                    $instruction,
                    $user->id,
                    true,
                )->onQueue('ai'),
            );

            return $lockedPost->fresh();
        });
    }

    public function restore(PlannedPost $plannedPost, PlannedPostRevision $revision, User $user): PlannedPost
    {
        return DB::transaction(function () use ($plannedPost, $revision, $user): PlannedPost {
            $lockedPost = PlannedPost::query()->lockForUpdate()->findOrFail($plannedPost->id);

            if ($revision->planned_post_id !== $lockedPost->id) {
                throw ValidationException::withMessages(['revision' => 'Версия относится к другой публикации.']);
            }

            if (in_array($lockedPost->status, [PlannedPostStatus::Publishing, PlannedPostStatus::Published, PlannedPostStatus::Cancelled], true)) {
                throw ValidationException::withMessages(['revision' => 'Эту публикацию уже нельзя восстановить из версии.']);
            }

            $lockedPost->deliveries()
                ->where('status', '!=', DeliveryStatus::Published)
                ->update(['status' => DeliveryStatus::Cancelled]);
            $lockedPost->update([
                'text' => $revision->text,
                'risk_flags' => $revision->risk_flags ?? [],
                'status' => ($revision->risk_flags ?? []) === [] ? PlannedPostStatus::FinalReview : PlannedPostStatus::Blocked,
                'rewrite_generation' => $lockedPost->rewrite_generation + 1,
                'approved_by' => null,
                'approved_at' => null,
                'override_by' => null,
                'override_reason' => null,
                'ai_review_status' => ($revision->risk_flags ?? []) === [] ? 'passed' : 'blocked',
            ]);
            $lockedPost->contentPlan()->update([
                'status' => ContentPlanStatus::FinalReview,
                'ready_at' => null,
            ]);
            ModerationAction::create([
                'user_id' => $user->id,
                'subject_type' => $lockedPost::class,
                'subject_id' => $lockedPost->id,
                'action' => ModerationActionType::RestorePostRevision,
                'metadata' => ['planned_post_revision_id' => $revision->id],
            ]);

            return $lockedPost->fresh();
        });
    }
}
