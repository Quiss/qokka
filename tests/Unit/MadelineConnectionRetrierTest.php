<?php

namespace Tests\Unit;

use Amp\CancelledException;
use Amp\TimeoutException;
use App\Services\MadelineConnectionRetrier;
use AssertionError;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

class MadelineConnectionRetrierTest extends TestCase
{
    public function test_it_retries_connection_failures_with_capped_backoff(): void
    {
        $retrier = new MadelineConnectionRetrierFake;
        $operationAttempts = 0;
        $retryNotifications = [];

        $retrier->run(
            function () use (&$operationAttempts): void {
                $operationAttempts++;

                if ($operationAttempts <= 5) {
                    throw new RuntimeException(
                        'Event loop error handler failed.',
                        previous: new AssertionError('Could not connect to DC 2.0!'),
                    );
                }
            },
            function (Throwable $exception, int $retryAttempt, int $delay) use (&$retryNotifications): void {
                $retryNotifications[] = [$retryAttempt, $delay, $exception->getMessage()];
            },
        );

        $this->assertSame(6, $operationAttempts);
        $this->assertSame([5, 15, 30, 60, 60], $retrier->pauses);
        $this->assertSame(
            [
                [1, 5, 'Event loop error handler failed.'],
                [2, 15, 'Event loop error handler failed.'],
                [3, 30, 'Event loop error handler failed.'],
                [4, 60, 'Event loop error handler failed.'],
                [5, 60, 'Event loop error handler failed.'],
            ],
            $retryNotifications,
        );
    }

    public function test_it_does_not_retry_unrelated_failures(): void
    {
        $retrier = new MadelineConnectionRetrierFake;
        $exception = new RuntimeException('Invalid session configuration.');

        try {
            $retrier->run(
                static fn (): never => throw $exception,
                static function (): void {},
            );
            $this->fail('Expected the unrelated failure to be rethrown.');
        } catch (RuntimeException $caughtException) {
            $this->assertSame($exception, $caughtException);
        }

        $this->assertSame([], $retrier->pauses);
    }

    public function test_it_reports_the_nested_connection_failure_reason(): void
    {
        $retrier = new MadelineConnectionRetrierFake;
        $exception = new RuntimeException(
            'Event loop error handler failed.',
            previous: new AssertionError('Could not connect to DC 2.0!'),
        );

        $this->assertSame('Could not connect to DC 2.0!', $retrier->reason($exception));
    }

    public function test_it_retries_amp_timeout_cancellations(): void
    {
        $retrier = new MadelineConnectionRetrierFake;
        $attempts = 0;

        $retrier->run(
            function () use (&$attempts): void {
                $attempts++;

                if ($attempts === 1) {
                    throw new CancelledException(new TimeoutException);
                }
            },
            static function (): void {},
        );

        $this->assertSame(2, $attempts);
        $this->assertSame([5], $retrier->pauses);
    }
}

class MadelineConnectionRetrierFake extends MadelineConnectionRetrier
{
    /** @var list<positive-int> */
    public array $pauses = [];

    protected function pause(int $seconds): void
    {
        $this->pauses[] = $seconds;
    }
}
