<?php

namespace App\Actions;

use App\ContentPlanStatus;
use App\Jobs\ReviewContentPlanJob;
use App\Jobs\RewritePlannedPostJob;
use App\Models\ContentPlan;
use App\PlannedPostStatus;
use Illuminate\Support\Facades\DB;

class RetryContentPlan
{
    public function __construct(private readonly QueueContentPlanGeneration $queueContentPlanGeneration) {}

    public function handle(ContentPlan $contentPlan): void
    {
        $retryMode = DB::transaction(function () use ($contentPlan): string {
            $lockedPlan = ContentPlan::query()
                ->with('plannedPosts')
                ->lockForUpdate()
                ->findOrFail($contentPlan->id);
            $failedPosts = $lockedPlan->plannedPosts
                ->where('status', PlannedPostStatus::Failed);

            if ($failedPosts->isNotEmpty()) {
                $failedPosts->each->update([
                    'status' => PlannedPostStatus::Rewriting,
                    'failure_reason' => null,
                    'failed_at' => null,
                ]);
                $lockedPlan->update([
                    'status' => ContentPlanStatus::Rewriting,
                    'failure_reason' => null,
                    'failed_at' => null,
                ]);

                DB::afterCommit(function () use ($failedPosts): void {
                    foreach ($failedPosts as $post) {
                        RewritePlannedPostJob::dispatch(
                            $post->id,
                            $post->rewrite_generation,
                        )->onQueue('ai');
                    }
                });

                return 'rewrites';
            }

            if ($lockedPlan->plannedPosts->isNotEmpty()) {
                $lockedPlan->update([
                    'status' => ContentPlanStatus::Rewriting,
                    'failure_reason' => null,
                    'failed_at' => null,
                ]);
                DB::afterCommit(
                    fn () => ReviewContentPlanJob::dispatch($lockedPlan->id)->onQueue('ai'),
                );

                return 'review';
            }

            return 'generation';
        });

        if ($retryMode === 'generation') {
            $this->queueContentPlanGeneration->handle($contentPlan, allowRegeneration: true);
        }
    }
}
