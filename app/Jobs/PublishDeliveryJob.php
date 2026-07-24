<?php

namespace App\Jobs;

use App\ContentPlanStatus;
use App\DeliveryStatus;
use App\Models\Delivery;
use App\PlannedPostStatus;
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
    public function handle(TelegramPublisher $publisher): void
    {
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
            $delivery->update([
                'status' => DeliveryStatus::Published,
                'external_message_ids' => $result['message_ids'],
                'published_at' => now(),
                'last_error' => null,
                'next_attempt_at' => null,
            ]);
            $plannedPost = $delivery->plannedPost;

            if (! $plannedPost->deliveries()->where('status', '!=', DeliveryStatus::Published)->exists()) {
                $plannedPost->update(['status' => PlannedPostStatus::Published, 'published_at' => now()]);

                if (! $plannedPost->contentPlan->plannedPosts()
                    ->whereNotIn('status', [PlannedPostStatus::Published, PlannedPostStatus::Cancelled])
                    ->exists()) {
                    $plannedPost->contentPlan->update(['status' => ContentPlanStatus::Completed]);
                }
            }
        } catch (ConnectionException $exception) {
            $delivery->update([
                'status' => DeliveryStatus::NeedsReview,
                'last_error' => $exception->getMessage(),
                'is_ambiguous' => true,
                'error_context' => ['reason' => 'connection_lost_during_publish'],
            ]);
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
        }
    }
}
