<?php

namespace App\Jobs;

use App\Actions\CompleteDeliveryPublication;
use App\ContentPlanStatus;
use App\DeliveryStatus;
use App\Models\Delivery;
use App\Services\TelegramPublisher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Throwable;

class PublishDeliveryJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public int $tries = 1;

    public function __construct(public readonly int $deliveryId) {}

    /**
     * Execute the job.
     */
    public function handle(
        TelegramPublisher $publisher,
        CompleteDeliveryPublication $completeDeliveryPublication,
    ): void {
        $delivery = DB::transaction(function (): ?Delivery {
            $delivery = Delivery::query()->lockForUpdate()->find($this->deliveryId);

            if ($delivery === null || ! in_array($delivery->status, [DeliveryStatus::Pending, DeliveryStatus::RetryScheduled], true)) {
                return null;
            }

            if ($delivery->next_attempt_at?->isFuture()) {
                return null;
            }

            $delivery->update(['status' => DeliveryStatus::Publishing, 'attempts' => $delivery->attempts + 1]);

            return $delivery;
        });

        if ($delivery === null) {
            return;
        }

        try {
            $delivery->plannedPost->contentPlan()->update(['status' => ContentPlanStatus::Active]);
            $result = $publisher->publish($delivery);
        } catch (ConnectionException $exception) {
            $delivery->update([
                'status' => DeliveryStatus::NeedsReview,
                'last_error' => $exception->getMessage(),
                'is_ambiguous' => true,
                'error_context' => ['reason' => 'connection_lost_during_publish'],
            ]);

            return;
        } catch (Throwable $exception) {
            $retryAfter = $exception instanceof RequestException && $exception->response->status() === 429
                ? (int) $exception->response->json('parameters.retry_after', 60)
                : min(1800, 60 * (2 ** max(0, $delivery->attempts - 1)));
            $shouldRetry = $delivery->attempts < 5
                && (! $exception instanceof RequestException || $exception->response->serverError() || $exception->response->status() === 429);
            $delivery->update([
                'status' => $shouldRetry ? DeliveryStatus::RetryScheduled : DeliveryStatus::Failed,
                'next_attempt_at' => $shouldRetry ? now()->addSeconds($retryAfter) : null,
                'last_error' => $exception->getMessage(),
                'error_context' => $exception instanceof RequestException ? ['status' => $exception->response->status()] : null,
            ]);

            return;
        }

        try {
            $completeDeliveryPublication->handle($delivery, $result['message_ids']);
        } catch (Throwable $exception) {
            report($exception);
            $delivery->update([
                'status' => DeliveryStatus::NeedsReview,
                'external_message_ids' => $result['message_ids'],
                'last_error' => $exception->getMessage(),
                'next_attempt_at' => null,
                'is_ambiguous' => true,
                'error_context' => ['reason' => 'state_update_failed_after_publish'],
            ]);
        }
    }
}
