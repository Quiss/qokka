<?php

namespace App\Services;

use App\Contracts\MadelineClient;
use App\Models\TelegramAccount;

class MadelineClientPool
{
    /** @var array<string, MadelineClient> */
    private array $clients = [];

    public function __construct(private readonly MadelineClientFactory $factory) {}

    public function forAccount(TelegramAccount $account): MadelineClient
    {
        return $this->clients[$account->uuid] ??= $this->factory->make($account);
    }

    public function forget(TelegramAccount $account): void
    {
        unset($this->clients[$account->uuid]);
    }
}
