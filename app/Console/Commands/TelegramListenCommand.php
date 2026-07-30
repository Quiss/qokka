<?php

namespace App\Console\Commands;

use App\Services\TelegramApiServerEventStream;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('telegram:listen')]
#[Description('Receive TelegramApiServer updates over WebSocket')]
class TelegramListenCommand extends Command
{
    public function handle(TelegramApiServerEventStream $eventStream): int
    {
        $this->info('Подключаюсь к TelegramApiServer WebSocket updates.');

        $eventStream->run(
            fn (\Throwable $exception, int $attempt) => $this->warn(
                "WebSocket TelegramApiServer недоступен: {$exception->getMessage()} "
                ."Попытка №{$attempt}.",
            ),
        );
    }
}
