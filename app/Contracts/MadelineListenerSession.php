<?php

namespace App\Contracts;

interface MadelineListenerSession
{
    public function isRemote(): bool;

    public function assertCanTakeOver(): void;

    public function prepareForTakeover(): void;
}
