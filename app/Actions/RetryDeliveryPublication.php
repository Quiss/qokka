<?php

namespace App\Actions;

use App\DeliveryStatus;
use App\Jobs\PublishDeliveryJob;
use App\Models\Delivery;
use App\Models\User;
use App\PlannedPostStatus;
use Illuminate\Support\Facades\DB;
use LogicException;

class RetryDeliveryPublication
{
    public function __construct(
        private readonly RecoverStaleDeliveryPublications $recoverStaleDeliveryPublications,
    ) {}

    public function isAvailable(Delivery $delivery): bool
    {
        if (in_array($delivery->status, [DeliveryStatus::NeedsReview, DeliveryStatus::Failed], true)) {
            return true;
        }

        return $this->recoverStaleDeliveryPublications->isStale($delivery);
    }

    public function handle(Delivery $delivery, ?User $requestedBy = null): bool
    {
        return DB::transaction(function () use ($delivery, $requestedBy): bool {
            $lockedDelivery = Delivery::query()
                ->with('plannedPost')
                ->lockForUpdate()
                ->findOrFail($delivery->id);

            if ($lockedDelivery->status === DeliveryStatus::RetryScheduled) {
                return false;
            }

            if (! $this->isAvailable($lockedDelivery)) {
                throw new LogicException("Delivery {$lockedDelivery->id} cannot be retried from status {$lockedDelivery->status->value}.");
            }

            if ($lockedDelivery->plannedPost->status !== PlannedPostStatus::Approved) {
                throw new LogicException("Planned post {$lockedDelivery->planned_post_id} is not approved for publication.");
            }

            $requestedAt = now();
            $lockedDelivery->update([
                'status' => DeliveryStatus::RetryScheduled,
                'next_attempt_at' => $requestedAt,
                'last_error' => null,
                'is_ambiguous' => false,
                'error_context' => [
                    'reason' => 'manual_retry_requested',
                    'requested_by' => $requestedBy?->id,
                    'requested_at' => $requestedAt->toIso8601String(),
                    'previous_error' => $lockedDelivery->last_error,
                    'previous_context' => $lockedDelivery->error_context,
                ],
            ]);

            PublishDeliveryJob::dispatch($lockedDelivery->id)
                ->onQueue('publish')
                ->afterCommit();

            return true;
        });
    }
}
