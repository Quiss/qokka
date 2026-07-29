<?php

namespace App\Contracts;

interface MadelineListenerSession
{
    public function hasRunningEventHandler(): bool;
}
