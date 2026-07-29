<?php

namespace App\Services;

use App\Contracts\MadelineListenerSession;
use App\Exceptions\MadelineOwnerUnavailableException;
use danog\MadelineProto\API;

final class MadelineProtoListenerSession implements MadelineListenerSession
{
    public function __construct(
        private readonly API $api,
        private readonly string $telegramAccountUuid,
        private readonly MadelineOwnerLease $ownerLease,
    ) {}

    public function isRemote(): bool
    {
        return $this->api->isIpc();
    }

    public function assertCanTakeOver(): void
    {
        if ($this->api->isIpc() && ! $this->api->isIpcWorker()) {
            throw new MadelineOwnerUnavailableException(
                'MadelineProto session is owned by another foreground process and cannot be taken over safely.',
            );
        }

        if ($this->api->isIpc() && $this->ownerLease->isFresh($this->telegramAccountUuid)) {
            throw new MadelineOwnerUnavailableException(
                'MadelineProto IPC worker still has a fresh foreground owner lease.',
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
