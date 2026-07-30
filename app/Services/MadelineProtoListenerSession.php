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
    ) {}

    public function isRemote(): bool
    {
        return false;
    }

    public function assertCanTakeOver(): void
    {
        if ($this->api->isIpc()) {
            throw new MadelineOwnerUnavailableException(
                "Telegram-сессия {$this->telegramAccountUuid} открылась через IPC вместо локального owner.",
            );
        }
    }

    public function prepareForTakeover(): void
    {
        $this->assertCanTakeOver();
    }

    public function api(): API
    {
        return $this->api;
    }
}
