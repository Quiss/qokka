<?php

namespace App\Jobs;

use App\Actions\RequestTelegramSourceVerification;
use App\Models\SourceChannel;
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

    public function __construct(public readonly int $sourceChannelId) {}

    public function uniqueId(): string
    {
        return (string) $this->sourceChannelId;
    }

    public function handle(RequestTelegramSourceVerification $requestVerification): void
    {
        $requestVerification->handle(
            SourceChannel::query()->findOrFail($this->sourceChannelId),
        );
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Telegram source verification failed.', [
            'source_channel_id' => $this->sourceChannelId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
