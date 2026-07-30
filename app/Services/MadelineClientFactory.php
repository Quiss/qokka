<?php

namespace App\Services;

use App\Contracts\MadelineClient;
use App\Models\TelegramAccount;

class MadelineClientFactory
{
    public function __construct(
        private readonly MadelineApiFactory $apiFactory,
        private readonly MadelineIpcCompatibility $ipcCompatibility,
    ) {}

    public function make(TelegramAccount $account): MadelineClient
    {
        return new MadelineProtoClient(
            $this->apiFactory->makeIpcClient($account),
            $this->ipcCompatibility,
        );
    }
}
