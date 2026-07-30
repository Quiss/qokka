<?php

namespace App\Jobs;

use App\Actions\RequestTelegramSourceHistorySync;
use App\Models\SourceChannel;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncSourceChannelStatisticsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly int $sourceChannelId,
        public readonly int $lookbackHours = 24,
    ) {}

    public function uniqueId(): string
    {
        return $this->sourceChannelId.':'.$this->lookbackHours;
    }

    public function handle(RequestTelegramSourceHistorySync $requestHistorySync): void
    {
        $sourceChannel = SourceChannel::query()->find($this->sourceChannelId);

        if ($sourceChannel !== null) {
            $requestHistorySync->handle($sourceChannel, $this->lookbackHours);
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Telegram source history request failed.', [
            'source_channel_id' => $this->sourceChannelId,
            'lookback_hours' => $this->lookbackHours,
            'error' => $exception?->getMessage(),
        ]);
    }
}
