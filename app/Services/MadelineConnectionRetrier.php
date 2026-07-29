<?php

namespace App\Services;

use Amp\CancelledException;
use Amp\Socket\ConnectException;
use Amp\TimeoutException;
use App\Exceptions\MadelineOwnerUnavailableException;
use Closure;
use Illuminate\Support\Str;
use Throwable;

class MadelineConnectionRetrier
{
    /**
     * @var list<positive-int>
     */
    private const array RETRY_DELAYS = [5, 15, 30, 60];

    /**
     * @param  Closure(): void  $operation
     * @param  Closure(Throwable, positive-int, positive-int): void  $onRetry
     */
    public function run(Closure $operation, Closure $onRetry): void
    {
        $retryAttempt = 0;

        while (true) {
            try {
                $operation();

                return;
            } catch (Throwable $exception) {
                if ($this->retryableException($exception) === null) {
                    throw $exception;
                }

                $retryAttempt++;
                $delay = self::RETRY_DELAYS[min($retryAttempt - 1, count(self::RETRY_DELAYS) - 1)];

                $onRetry($exception, $retryAttempt, $delay);
                $this->pause($delay);
            }
        }
    }

    public function reason(Throwable $exception): string
    {
        return $this->retryableException($exception)?->getMessage() ?? $exception->getMessage();
    }

    protected function pause(int $seconds): void
    {
        sleep($seconds);
    }

    private function retryableException(Throwable $exception): ?Throwable
    {
        do {
            if (
                $exception instanceof ConnectException
                || $exception instanceof CancelledException
                || $exception instanceof TimeoutException
                || $exception instanceof MadelineOwnerUnavailableException
                || Str::contains($exception->getMessage(), [
                    'Could not connect to DC ',
                    'Could not connect to MadelineProto',
                    'operation was cancelled',
                    'Operation timed out',
                    'Timeout during session unserialization',
                ], ignoreCase: true)
            ) {
                return $exception;
            }

            $exception = $exception->getPrevious();
        } while ($exception !== null);

        return null;
    }
}
