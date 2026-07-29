<?php

namespace App\Exceptions;

use RuntimeException;

class PermanentTelegramMediaException extends RuntimeException
{
    /** @return array<string, mixed> */
    public function context(): array
    {
        return ['error_type' => 'permanent_telegram_media_failure'];
    }
}
