<?php

namespace App\Exceptions;

use RuntimeException;

class MadelineOwnerUnavailableException extends RuntimeException
{
    /** @return array<string, mixed> */
    public function context(): array
    {
        return ['error_type' => 'madeline_owner_unavailable'];
    }
}
