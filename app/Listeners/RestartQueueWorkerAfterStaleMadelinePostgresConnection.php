<?php

namespace App\Listeners;

use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Support\Facades\Log;
use Throwable;

class RestartQueueWorkerAfterStaleMadelinePostgresConnection
{
    public function handle(JobExceptionOccurred $event): void
    {
        if (! $this->isStaleMadelinePostgresStatement($event->exception)) {
            return;
        }

        $worker = app('queue.worker');
        $worker->shouldQuit = true;

        Log::warning('Queue worker will restart after a stale MadelineProto PostgreSQL prepared statement.', [
            'connection' => $event->connectionName,
            'queue' => $event->job->getQueue(),
            'job' => $event->job->resolveName(),
            'error' => $event->exception->getMessage(),
        ]);
    }

    private function isStaleMadelinePostgresStatement(Throwable $exception): bool
    {
        do {
            $message = $exception->getMessage();

            if (
                str_contains($message, 'prepared statement "amp_')
                && str_contains($message, 'does not exist')
            ) {
                return true;
            }

            $exception = $exception->getPrevious();
        } while ($exception !== null);

        return false;
    }
}
