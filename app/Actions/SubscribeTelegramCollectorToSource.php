<?php

namespace App\Actions;

use App\Models\SourceChannel;
use App\Models\TelegramAccount;
use App\Services\MadelineClientPool;
use danog\MadelineProto\RPCErrorException;
use Throwable;

class SubscribeTelegramCollectorToSource
{
    public function __construct(private readonly MadelineClientPool $clientPool) {}

    public function handle(TelegramAccount $telegramAccount, SourceChannel $sourceChannel): void
    {
        if (blank($sourceChannel->username)) {
            return;
        }

        $peer = '@'.$sourceChannel->username;

        try {
            $client = $this->clientPool->forAccount($telegramAccount);

            try {
                $client->joinChannel($peer);
            } catch (RPCErrorException $exception) {
                if ($exception->rpc !== 'USER_ALREADY_PARTICIPANT') {
                    throw $exception;
                }
            }

            $client->muteNotifications($peer);
        } catch (Throwable $exception) {
            $this->clientPool->forget($telegramAccount);

            throw $exception;
        }
    }
}
