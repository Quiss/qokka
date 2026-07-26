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
        return $this->approveWithActor($plannedPost, $user, $overrideReason);
    }

    public function approveAutomatically(PlannedPost $plannedPost): PlannedPost
    {
        $plannedPost->refresh();

        if (in_array($plannedPost->status, [
            PlannedPostStatus::Approved,
            PlannedPostStatus::Publishing,
            PlannedPostStatus::Published,
        ], true)) {
            return $plannedPost->load('deliveries');
        }

        if (! $this->canApproveAutomatically($plannedPost)) {
            throw ValidationException::withMessages([
                'risk_flags' => 'Автоматически можно одобрить только публикацию, прошедшую итоговую AI-проверку без рисков.',
            ]);
        }

        $plannedPost->loadMissing('contentPlan.publication.destination');

        if (! $plannedPost->contentPlan->publication->destination?->is_active) {
            throw ValidationException::withMessages([
                'destination' => 'Активный канал назначения не настроен.',
            ]);
        }

        return $this->approveWithActor($plannedPost, null, null, true);
    }

    private function approveWithActor(
        PlannedPost $plannedPost,
        ?User $user,
        ?string $overrideReason,
        bool $isAutomatic = false,
    ): PlannedPost {
        $plannedPost->loadMissing('contentPlan.publication.destination', 'contentPlan.plannedPosts');
        $this->mediaManager->syncAvailableOrigins($plannedPost);

        if ($this->mediaManager->hasUnpreparedSelection($plannedPost)) {
            throw ValidationException::withMessages([
                'media' => $this->mediaManager->hasFailedSelection($plannedPost)
                    ? 'Не удалось загрузить выбранное медиа из Telegram. Нажмите «Повторить загрузку медиа» и дождитесь завершения.'
                    : 'Выбранное медиа загружается из Telegram. Дождитесь завершения и повторите одобрение.',
            ]);
        }

        $scheduledAt = $this->resolveSchedule($plannedPost);

        if ($scheduledAt === null) {
            $plannedPost->update(['status' => PlannedPostStatus::NeedsReschedule]);

            throw ValidationException::withMessages([
                'scheduled_at' => 'На этот день не осталось свободных слотов. Назначьте время вручную.',
            ]);
        }

        return DB::transaction(function () use ($plannedPost, $user, $overrideReason, $scheduledAt, $isAutomatic): PlannedPost {
            $lockedPost = PlannedPost::query()
                ->with('contentPlan.publication.destination', 'contentPlan.plannedPosts')
                ->lockForUpdate()
                ->findOrFail($plannedPost->id);

            if (in_array($lockedPost->status, [
                PlannedPostStatus::Approved,
                PlannedPostStatus::Publishing,
                PlannedPostStatus::Published,
            ], true)) {
                return $lockedPost->load('deliveries');
            }

            if ($isAutomatic && ! $this->canApproveAutomatically($lockedPost)) {
                throw ValidationException::withMessages([
                    'risk_flags' => 'Публикация изменилась и больше не может быть одобрена автоматически.',
                ]);
            }

            $lockedRiskFlags = array_values(array_filter($lockedPost->risk_flags ?? []));
            $lockedPost->update([
                'scheduled_at' => $scheduledAt,
                'status' => PlannedPostStatus::Approved,
                'approved_by' => $user?->id,
                'approved_at' => now(),
                'override_by' => ! $isAutomatic && $lockedRiskFlags !== [] ? $user?->id : null,
                'override_reason' => ! $isAutomatic && $lockedRiskFlags !== [] ? $overrideReason : null,
            ]);

            $destination = $lockedPost->contentPlan->publication->destination;

            if ($destination?->is_active) {
                $lockedPost->deliveries()->updateOrCreate(
                    ['destination_id' => $destination->id],
                    ['status' => DeliveryStatus::Pending, 'next_attempt_at' => $scheduledAt],
                );
            }

            ModerationAction::create([
                'user_id' => $user?->id,
                'subject_type' => $lockedPost::class,
                'subject_id' => $lockedPost->id,
                'action' => $isAutomatic
                    ? ModerationActionType::SafetyNetApprovePost
                    : ($lockedRiskFlags !== [] ? ModerationActionType::OverrideAiBlock : ModerationActionType::ApprovePost),
                'reason' => $overrideReason,
                'metadata' => ['risk_flags' => $lockedRiskFlags, 'automatic' => $isAutomatic],
            ]);

            $activePosts = $lockedPost->contentPlan->plannedPosts()
                ->where('status', '!=', PlannedPostStatus::Cancelled);
            $hasAllSlots = (clone $activePosts)->count() >= count($lockedPost->contentPlan->slot_schedule ?? []);
            $hasIncompletePosts = (clone $activePosts)
                ->whereNotIn('status', [PlannedPostStatus::Approved, PlannedPostStatus::Publishing, PlannedPostStatus::Published])
                ->exists();

            if ($hasAllSlots && ! $hasIncompletePosts) {
                $lockedPost->contentPlan->update(['status' => ContentPlanStatus::Ready, 'ready_at' => now()]);
            }

            return $lockedPost->fresh(['deliveries']);
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

    private function canApproveAutomatically(PlannedPost $plannedPost): bool
    {
        return in_array($plannedPost->status, [
            PlannedPostStatus::FinalReview,
            PlannedPostStatus::NeedsReschedule,
        ], true)
            && $plannedPost->ai_review_status === 'passed'
            && array_values(array_filter($plannedPost->risk_flags ?? [])) === [];
    }
}
