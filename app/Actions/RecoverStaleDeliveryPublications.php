<?php

namespace App\Actions;

use App\DeliveryStatus;
use App\Filament\Resources\Deliveries\DeliveryResource;
use App\Models\Delivery;
use App\OperationsNotificationTopic;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RecoverStaleDeliveryPublications
{
    public function __construct(
        private readonly QueueOperationsNotification $queueOperationsNotification,
    ) {}

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
        $recoveredIds = [];

        Delivery::query()
            ->where('status', DeliveryStatus::Publishing)
            ->where('updated_at', '<=', $cutoff)
            ->orderBy('id')
            ->pluck('id')
            ->each(function (int $deliveryId) use ($cutoff, $detectedAt, &$recovered, &$recoveredIds): void {
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
                    $recoveredIds[] = $deliveryId;
                }
            });

        if ($recoveredIds !== []) {
            $visibleIds = array_slice($recoveredIds, 0, 20);
            $idsLine = 'Delivery: #'.implode(', #', $visibleIds);

            if (count($recoveredIds) > count($visibleIds)) {
                $idsLine .= ' и ещё '.(count($recoveredIds) - count($visibleIds));
            }

            $this->queueOperationsNotification->handle(
                OperationsNotificationTopic::Failures,
                'Обнаружены зависшие публикации',
                [
                    'Количество: '.count($recoveredIds),
                    $idsLine,
                    'Требуется ручная проверка отправки в Telegram.',
                ],
                DeliveryResource::getUrl('index', panel: 'admin'),
            );
        }

        return $recovered;
    }

    private function staleAfterSeconds(): int
    {
        return max(1, (int) config('services.telegram.publishing_stale_after', 600));
    }
}
