<?php

namespace Tests\Unit;

use App\Services\MadelineIpcCompatibility;
use ErrorException;
use PHPUnit\Framework\TestCase;

class MadelineIpcCompatibilityTest extends TestCase
{
    public function test_it_suppresses_the_php_85_string_increment_deprecation(): void
    {
        $previousHandler = set_error_handler(
            static fn (
                int $severity,
                string $message,
                string $file,
                int $line,
            ): never => throw new ErrorException($message, 0, $severity, $file, $line),
        );

        try {
            $result = (new MadelineIpcCompatibility)->runWithCancellation(
                static function (): string {
                    $id = 'a';
                    $id++;

                    return $id;
                },
            );
        } finally {
            restore_error_handler();
        }

        $this->assertSame('b', $result);
        $this->assertSame($previousHandler, $this->currentErrorHandler());
    }

    public function test_it_delegates_unrelated_errors_to_the_previous_handler(): void
    {
        $caughtException = null;
        set_error_handler(
            static fn (
                int $severity,
                string $message,
                string $file,
                int $line,
            ): never => throw new ErrorException($message, 0, $severity, $file, $line),
        );

        try {
            (new MadelineIpcCompatibility)->runWithCancellation(
                static fn (): bool => trigger_error('Unrelated warning.', E_USER_WARNING),
            );
        } catch (ErrorException $exception) {
            $caughtException = $exception;
        } finally {
            restore_error_handler();
        }

        $this->assertInstanceOf(ErrorException::class, $caughtException);
        $this->assertSame('Unrelated warning.', $caughtException->getMessage());
    }

    private function currentErrorHandler(): ?callable
    {
        $currentHandler = set_error_handler(static fn (): bool => false);
        restore_error_handler();

        return $currentHandler;
    }
}
