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

        try {
            $this->clientPool
                ->forAccount($telegramAccount)
                ->joinChannel('@'.$sourceChannel->username);
        } catch (RPCErrorException $exception) {
            if ($exception->rpc === 'USER_ALREADY_PARTICIPANT') {
                return;
            }

            $this->clientPool->forget($telegramAccount);

            throw $exception;
        } catch (Throwable $exception) {
            $this->clientPool->forget($telegramAccount);

            throw $exception;
        }
    }
}
