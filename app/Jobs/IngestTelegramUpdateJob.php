<?php

namespace App\Jobs;

use App\Actions\IngestTelegramUpdate;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class IngestTelegramUpdateJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    /** @param array<string, mixed> $payload */
    public function __construct(public readonly array $payload) {}

    /**
     * Execute the job.
     */
    public function handle(IngestTelegramUpdate $ingestTelegramUpdate): void
    {
        $ingestTelegramUpdate->handle($this->payload);
    }
}
