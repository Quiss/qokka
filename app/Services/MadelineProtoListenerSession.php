<?php

namespace App\Services;

use App\Contracts\MadelineListenerSession;
use danog\MadelineProto\API;

final class MadelineProtoListenerSession implements MadelineListenerSession
{
    public function __construct(private readonly API $api) {}

    public function hasRunningEventHandler(): bool
    {
        return $this->api->isIpc() && $this->api->hasEventHandler();
    }

    public function api(): API
    {
        return $this->api;
    }
}
