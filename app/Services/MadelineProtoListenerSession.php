<?php

namespace App\Services;

use App\Contracts\MadelineListenerSession;
use danog\MadelineProto\API;
use RuntimeException;

final class MadelineProtoListenerSession implements MadelineListenerSession
{
    public function __construct(private readonly API $api) {}

    public function isRemote(): bool
    {
        return $this->api->isIpc();
    }

    public function assertCanTakeOver(): void
    {
        if ($this->api->isIpc() && ! $this->api->isIpcWorker()) {
            throw new RuntimeException(
                'MadelineProto session is owned by another foreground process and cannot be taken over safely.',
            );
        }
    }

    public function prepareForTakeover(): void
    {
        $this->assertCanTakeOver();

        if ($this->api->isIpc() && $this->api->hasEventHandler()) {
            $this->api->unsetEventHandler();
        }
    }

    public function api(): API
    {
        return $this->api;
    }
}
