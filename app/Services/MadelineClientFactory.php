<?php

namespace App\Services;

use App\Contracts\MadelineClient;
use App\Models\TelegramAccount;
use danog\MadelineProto\API;

class MadelineClientFactory
{
    public function __construct(private readonly MadelineSettingsFactory $settingsFactory) {}

    public function make(TelegramAccount $account): MadelineClient
    {
        return new MadelineProtoClient(
            new API($this->settingsFactory->sessionPath($account)),
        );
    }
}
