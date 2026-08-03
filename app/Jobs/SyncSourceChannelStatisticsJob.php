<?php

namespace App\Jobs;

use App\Actions\RequestTelegramSourceHistorySync;
use App\Models\Source;
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
        public readonly int $sourceId,
        public readonly int $lookbackHours = 24,
    ) {}

    public function uniqueId(): string
    {
        return $this->sourceId.':'.$this->lookbackHours;
    }

    public function handle(RequestTelegramSourceHistorySync $requestHistorySync): void
    {
        $source = Source::query()->find($this->sourceId);

        if ($source !== null) {
            $requestHistorySync->handle($source, $this->lookbackHours);
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Telegram source history request failed.', [
            'source_id' => $this->sourceId,
            'lookback_hours' => $this->lookbackHours,
            'error' => $exception?->getMessage(),
        ]);
    }
}
