<?php

namespace App\Jobs;

use App\Contracts\OperationsNotifier;
use App\OperationsNotificationTopic;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendOperationsNotificationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 15;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    /** @param list<string> $details */
    public function __construct(
        public readonly OperationsNotificationTopic $topic,
        public readonly string $title,
        public readonly array $details,
        public readonly string $url,
    ) {}

    public function handle(OperationsNotifier $notifier): void
    {
        $notifier->send($this->topic, $this->title, $this->details, $this->url);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Telegram operations notification delivery failed.', [
            'topic' => $this->topic->value,
            'title' => $this->title,
            'exception' => $exception !== null ? $exception::class : null,
            'error' => $exception?->getMessage(),
        ]);
    }
}
