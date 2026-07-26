<?php

namespace App\Actions;

use App\ContentPlanStatus;
use App\Jobs\GenerateCandidateBatchJob;
use App\Models\ContentPlan;
use Illuminate\Support\Facades\DB;

class QueueContentPlanGeneration
{
    public function handle(ContentPlan $contentPlan, bool $allowRegeneration = false): bool
    {
        $queued = DB::transaction(function () use ($contentPlan, $allowRegeneration): bool {
            $lockedPlan = ContentPlan::query()->lockForUpdate()->findOrFail($contentPlan->id);

            if ($lockedPlan->status === ContentPlanStatus::Generating) {
                return false;
            }

            if ($lockedPlan->generated_at !== null && ! $allowRegeneration) {
                return false;
            }

            if ($lockedPlan->plannedPosts()->exists()) {
                return false;
            }

            $lockedPlan->update([
                'status' => ContentPlanStatus::Generating,
                'failure_reason' => null,
                'failed_at' => null,
            ]);

            DB::afterCommit(
                fn () => GenerateCandidateBatchJob::dispatch($lockedPlan->id)->onQueue('ai'),
            );

            return true;
        });

        return $queued;
    }
}
