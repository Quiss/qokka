<?php

namespace App\Actions;

use App\DeliveryStatus;
use App\Models\Delivery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RecoverStaleDeliveryPublications
{
    public function isStale(Delivery $delivery, ?Carbon $now = null): bool
    {
        return $delivery->status === DeliveryStatus::Publishing
            && $delivery->updated_at->lte(($now ?? now())->clone()->subSeconds($this->staleAfterSeconds()));
    }

    public function handle(): int
    {
        $detectedAt = now();
        $cutoff = $detectedAt->clone()->subSeconds($this->staleAfterSeconds());
        $recovered = 0;

        Delivery::query()
            ->where('status', DeliveryStatus::Publishing)
            ->where('updated_at', '<=', $cutoff)
            ->orderBy('id')
            ->pluck('id')
            ->each(function (int $deliveryId) use ($cutoff, $detectedAt, &$recovered): void {
                $wasRecovered = DB::transaction(function () use ($deliveryId, $cutoff, $detectedAt): bool {
                    $delivery = Delivery::query()
                        ->lockForUpdate()
                        ->find($deliveryId);

                    if ($delivery === null
                        || $delivery->status !== DeliveryStatus::Publishing
                        || $delivery->updated_at->gt($cutoff)) {
                        return false;
                    }

                    $delivery->update([
                        'status' => DeliveryStatus::NeedsReview,
                        'last_error' => 'Отправка прервалась до подтверждения результата. Проверьте наличие поста в Telegram.',
                        'next_attempt_at' => null,
                        'is_ambiguous' => true,
                        'error_context' => [
                            'reason' => 'stale_publishing_recovered',
                            'detected_at' => $detectedAt->toIso8601String(),
                            'stale_after_seconds' => $this->staleAfterSeconds(),
                            'previous_context' => $delivery->error_context,
                        ],
                    ]);

                    return true;
                });

                if ($wasRecovered) {
                    $recovered++;
                }
            });

        return $recovered;
    }

    private function staleAfterSeconds(): int
    {
        return max(1, (int) config('services.telegram.publishing_stale_after', 600));
    }
}
