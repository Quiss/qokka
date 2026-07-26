<?php

namespace App\Jobs;

use App\Actions\GenerateCandidateBatch;
use App\ContentPlanStatus;
use App\Models\ContentPlan;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ReplenishContentPlanCandidatesJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 330;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public readonly int $contentPlanId,
        public readonly int $candidateTarget,
        public readonly ContentPlanStatus $completionStatus = ContentPlanStatus::NeedsCandidates,
        public readonly bool $extendLookback = true,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->contentPlanId;
    }

    public function handle(GenerateCandidateBatch $generateCandidateBatch): void
    {
        $plan = ContentPlan::query()->findOrFail($this->contentPlanId);
        $before = $plan->storyCandidates()->count();
        $generateCandidateBatch->handle($plan, append: true, lookbackHours: 24, targetOverride: $this->candidateTarget);
        $created = $plan->storyCandidates()->count() - $before;

        if ($this->extendLookback && $created < $this->candidateTarget) {
            $generateCandidateBatch->handle(
                $plan->fresh(),
                append: true,
                lookbackHours: 48,
                targetOverride: $this->candidateTarget - $created,
            );
        }

        $plan->update([
            'status' => $this->completionStatus,
            'failure_reason' => null,
            'failed_at' => null,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        ContentPlan::query()->whereKey($this->contentPlanId)->update([
            'status' => $this->completionStatus,
            'failure_reason' => $exception?->getMessage() ?? 'Не удалось добрать кандидатов.',
            'failed_at' => now(),
        ]);
    }
}
