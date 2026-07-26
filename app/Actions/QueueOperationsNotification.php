<?php

namespace App\Actions;

use App\Jobs\SendOperationsNotificationJob;
use App\OperationsNotificationTopic;
use Illuminate\Support\Facades\Log;
use Throwable;

class QueueOperationsNotification
{
    /** @param list<string> $details */
    public function handle(
        OperationsNotificationTopic $topic,
        string $title,
        array $details,
        string $url,
    ): void {
        if (! $this->isConfigured($topic)) {
            Log::warning('Telegram operations notification is not configured.', [
                'topic' => $topic->value,
            ]);

            return;
        }

        try {
            SendOperationsNotificationJob::dispatch($topic, $title, $details, $url)
                ->onQueue('default');
        } catch (Throwable $exception) {
            Log::error('Unable to queue Telegram operations notification.', [
                'topic' => $topic->value,
                'exception' => $exception::class,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function isConfigured(OperationsNotificationTopic $topic): bool
    {
        return filled(config('services.telegram.bot_token'))
            && filled(config('services.telegram.operations.chat_id'))
            && (int) config('services.telegram.operations.topics.'.$topic->configKey()) > 0;
    }
}
