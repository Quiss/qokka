<?php

namespace App\Jobs;

use App\DeliveryStatus;
use App\Models\Delivery;
use App\PlannedPostStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DispatchDueDeliveriesJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Delivery::query()
            ->whereIn('status', [DeliveryStatus::Pending, DeliveryStatus::RetryScheduled])
            ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->whereHas('plannedPost', fn ($query) => $query
                ->where('status', PlannedPostStatus::Approved)
                ->where('scheduled_at', '<=', now()))
            ->orderBy('id')
            ->pluck('id')
            ->each(fn (int $id) => PublishDeliveryJob::dispatch($id)->onQueue('publish'));
    }
}
