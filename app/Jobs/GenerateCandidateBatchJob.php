<?php

namespace App\Jobs;

use App\Actions\GenerateCandidateBatch;
use App\ContentPlanStatus;
use App\Models\ContentPlan;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class GenerateCandidateBatchJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public int $tries = 3;

    public int $timeout = 330;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function __construct(public readonly int $contentPlanId) {}

    public function uniqueId(): string
    {
        return (string) $this->contentPlanId;
    }

    /**
     * Execute the job.
     */
    public function handle(GenerateCandidateBatch $generateCandidateBatch): void
    {
        $generateCandidateBatch->handle(ContentPlan::query()->findOrFail($this->contentPlanId));
    }

    public function failed(?Throwable $exception): void
    {
        ContentPlan::query()->whereKey($this->contentPlanId)->update([
            'status' => ContentPlanStatus::Failed,
            'failure_reason' => $exception?->getMessage() ?? 'Неизвестная ошибка генерации подборки.',
            'failed_at' => now(),
        ]);
    }
}
