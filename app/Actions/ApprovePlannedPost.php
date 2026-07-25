<?php

namespace App\Actions;

use App\ContentPlanStatus;
use App\DeliveryStatus;
use App\Models\ModerationAction;
use App\Models\PlannedPost;
use App\Models\User;
use App\ModerationActionType;
use App\PlannedPostStatus;
use App\Services\PlannedPostMediaManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApprovePlannedPost
{
    public function __construct(
        private readonly PlannedPostMediaManager $mediaManager,
        private readonly ReplaceRejectedPlannedPost $replaceRejectedPlannedPost,
    ) {}

    public function approve(PlannedPost $plannedPost, User $user, ?string $overrideReason = null): PlannedPost
    {
        $plannedPost->loadMissing('contentPlan.publication.destination', 'contentPlan.plannedPosts');
        $riskFlags = array_values(array_filter($plannedPost->risk_flags ?? []));
        $this->mediaManager->syncAvailableOrigins($plannedPost);

        if ($this->mediaManager->hasUnpreparedSelection($plannedPost)) {
            $this->mediaManager->queueUnpreparedSelectionDownloads($plannedPost);

            throw ValidationException::withMessages([
                'media' => 'Выбранное медиа ещё не готово. Повторная загрузка поставлена в очередь — попробуйте одобрить публикацию через минуту.',
            ]);
        }

        $scheduledAt = $this->resolveSchedule($plannedPost);

        if ($scheduledAt === null) {
            $plannedPost->update(['status' => PlannedPostStatus::NeedsReschedule]);

            throw ValidationException::withMessages([
                'scheduled_at' => 'На этот день не осталось свободных слотов. Назначьте время вручную.',
            ]);
        }

        return DB::transaction(function () use ($plannedPost, $user, $overrideReason, $riskFlags, $scheduledAt): PlannedPost {
            $plannedPost->update([
                'scheduled_at' => $scheduledAt,
                'status' => PlannedPostStatus::Approved,
                'approved_by' => $user->id,
                'approved_at' => now(),
                'override_by' => $riskFlags !== [] ? $user->id : null,
                'override_reason' => $riskFlags !== [] ? $overrideReason : null,
            ]);

            $destination = $plannedPost->contentPlan->publication->destination;

            if ($destination?->is_active) {
                $plannedPost->deliveries()->updateOrCreate(
                    ['destination_id' => $destination->id],
                    ['status' => DeliveryStatus::Pending, 'next_attempt_at' => $scheduledAt],
                );
            }

            ModerationAction::create([
                'user_id' => $user->id,
                'subject_type' => $plannedPost::class,
                'subject_id' => $plannedPost->id,
                'action' => $riskFlags !== [] ? ModerationActionType::OverrideAiBlock : ModerationActionType::ApprovePost,
                'reason' => $overrideReason,
                'metadata' => ['risk_flags' => $riskFlags],
            ]);

            $activePosts = $plannedPost->contentPlan->plannedPosts()
                ->where('status', '!=', PlannedPostStatus::Cancelled);
            $hasAllSlots = (clone $activePosts)->count() >= count($plannedPost->contentPlan->slot_schedule ?? []);
            $hasIncompletePosts = (clone $activePosts)
                ->whereNotIn('status', [PlannedPostStatus::Approved, PlannedPostStatus::Publishing, PlannedPostStatus::Published])
                ->exists();

            if ($hasAllSlots && ! $hasIncompletePosts) {
                $plannedPost->contentPlan->update(['status' => ContentPlanStatus::Ready, 'ready_at' => now()]);
            }

            return $plannedPost->fresh(['deliveries']);
        });
    }

    public function reject(PlannedPost $plannedPost, User $user, string $reason): PlannedPost
    {
        return $this->replaceRejectedPlannedPost->handle($plannedPost, $user, $reason)
            ?? $plannedPost->fresh();
    }

    private function resolveSchedule(PlannedPost $plannedPost): ?CarbonImmutable
    {
        $scheduledAt = CarbonImmutable::parse($plannedPost->scheduled_at);

        if ($scheduledAt->isFuture()) {
            return $scheduledAt;
        }

        $occupied = $plannedPost->contentPlan->plannedPosts
            ->where('id', '!=', $plannedPost->id)
            ->filter(fn (PlannedPost $post): bool => in_array($post->status, [PlannedPostStatus::Approved, PlannedPostStatus::Publishing, PlannedPostStatus::Published], true))
            ->pluck('scheduled_at')
            ->filter()
            ->map(fn ($date): string => $date->toIso8601String());

        return collect($plannedPost->contentPlan->slot_schedule ?? [])
            ->map(fn (string $slot): CarbonImmutable => CarbonImmutable::parse($slot))
            ->first(fn (CarbonImmutable $slot): bool => $slot->isFuture() && ! $occupied->contains($slot->toIso8601String()));
    }
}
