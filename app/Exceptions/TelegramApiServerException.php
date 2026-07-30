<?php

namespace App\Exceptions;

use RuntimeException;

class TelegramApiServerException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $rpc = null,
        int $code = 0,
    ) {
        parent::__construct($message, $code);
    }
}
