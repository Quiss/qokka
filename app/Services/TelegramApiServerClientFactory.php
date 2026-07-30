<?php

namespace App\Services;

use App\Contracts\MadelineClient;
use App\Models\TelegramAccount;

class TelegramApiServerClientFactory
{
    public function __construct(private readonly TelegramApiServer $server) {}

    public function forAccount(TelegramAccount $telegramAccount): MadelineClient
    {
        return new TelegramApiServerClient($this->server, $telegramAccount->uuid);
    }
}
