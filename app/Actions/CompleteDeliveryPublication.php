<?php

namespace App\Actions;

use App\ContentPlanStatus;
use App\DeliveryStatus;
use App\Models\Delivery;
use App\Models\User;
use App\PlannedPostStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

class CompleteDeliveryPublication
{
    /**
     * @param  list<string>|null  $externalMessageIds
     */
    public function handle(
        Delivery $delivery,
        ?array $externalMessageIds = null,
        ?User $confirmedBy = null,
        ?Carbon $publishedAt = null,
    ): Delivery {
        return DB::transaction(function () use ($delivery, $externalMessageIds, $confirmedBy, $publishedAt): Delivery {
            $lockedDelivery = Delivery::query()
                ->with('plannedPost.contentPlan')
                ->lockForUpdate()
                ->findOrFail($delivery->id);

            if ($lockedDelivery->status === DeliveryStatus::Published) {
                return $lockedDelivery;
            }

            $isManualConfirmation = $externalMessageIds === null;
            $allowedStatuses = $isManualConfirmation
                ? [DeliveryStatus::Publishing, DeliveryStatus::NeedsReview]
                : [DeliveryStatus::Publishing];

            if (! in_array($lockedDelivery->status, $allowedStatuses, true)) {
                throw new LogicException("Delivery {$lockedDelivery->id} cannot be completed from status {$lockedDelivery->status->value}.");
            }

            $completedAt = $publishedAt ?? now();
            $updates = [
                'status' => DeliveryStatus::Published,
                'published_at' => $completedAt,
                'last_error' => null,
                'next_attempt_at' => null,
                'is_ambiguous' => false,
                'error_context' => $isManualConfirmation
                    ? [
                        'reason' => 'manually_confirmed_published',
                        'confirmed_by' => $confirmedBy?->id,
                        'confirmed_at' => $completedAt->toIso8601String(),
                        'previous_error' => $lockedDelivery->last_error,
                        'previous_context' => $lockedDelivery->error_context,
                    ]
                    : null,
            ];

            if ($externalMessageIds !== null) {
                $updates['external_message_ids'] = $externalMessageIds;
            }

            $lockedDelivery->update($updates);
            $plannedPost = $lockedDelivery->plannedPost;

            if (! $plannedPost->deliveries()->where('status', '!=', DeliveryStatus::Published)->exists()) {
                $plannedPost->update([
                    'status' => PlannedPostStatus::Published,
                    'published_at' => $completedAt,
                ]);

                if (! $plannedPost->contentPlan->plannedPosts()
                    ->whereNotIn('status', [PlannedPostStatus::Published, PlannedPostStatus::Cancelled])
                    ->exists()) {
                    $plannedPost->contentPlan->update(['status' => ContentPlanStatus::Completed]);
                }
            }

            return $lockedDelivery->refresh()->load('plannedPost.contentPlan');
        });
    }
}
