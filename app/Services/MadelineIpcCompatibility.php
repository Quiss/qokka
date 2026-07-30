<?php

namespace App\Services;

use Closure;

class MadelineIpcCompatibility
{
    private const string PHP_85_STRING_INCREMENT_DEPRECATION = 'Increment on non-numeric string is deprecated, use str_increment() instead';

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $operation
     * @return TResult
     */
    public function runWithCancellation(Closure $operation): mixed
    {
        $previousHandler = null;
        $previousHandler = set_error_handler(
            static function (
                int $severity,
                string $message,
                string $file,
                int $line,
            ) use (&$previousHandler): bool {
                if (
                    $severity === E_DEPRECATED
                    && $message === self::PHP_85_STRING_INCREMENT_DEPRECATION
                ) {
                    return true;
                }

                if ($previousHandler === null) {
                    return false;
                }

                return (bool) $previousHandler($severity, $message, $file, $line);
            },
        );

        try {
            return $operation();
        } finally {
            restore_error_handler();
        }
    }
}
