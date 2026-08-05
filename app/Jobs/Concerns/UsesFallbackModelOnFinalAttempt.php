<?php

namespace App\Jobs\Concerns;

trait UsesFallbackModelOnFinalAttempt
{
    private function shouldUseFallbackModel(): bool
    {
        return $this->attempts() >= $this->tries;
    }
}
