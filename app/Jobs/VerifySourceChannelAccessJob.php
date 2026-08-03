<?php

namespace App\Jobs;

use App\Actions\RequestTelegramSourceVerification;
use App\Models\Source;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class VerifySourceChannelAccessJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 300];

    public function __construct(public readonly int $sourceId) {}

    public function uniqueId(): string
    {
        return (string) $this->sourceId;
    }

    public function handle(RequestTelegramSourceVerification $requestVerification): void
    {
        $requestVerification->handle(
            Source::query()->findOrFail($this->sourceId),
        );
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Telegram source verification failed.', [
            'source_id' => $this->sourceId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
