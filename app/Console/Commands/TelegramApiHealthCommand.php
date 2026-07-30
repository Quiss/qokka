<?php

namespace App\Console\Commands;

use App\Services\TelegramApiServer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('telegram:api:health')]
#[Description('Check TelegramApiServer HTTP readiness')]
class TelegramApiHealthCommand extends Command
{
    public function handle(TelegramApiServer $server): int
    {
        try {
            $sessions = $server->sessions();
        } catch (Throwable $exception) {
            $this->error('TelegramApiServer недоступен: '.$exception->getMessage());

            return self::FAILURE;
        }

        $loggedIn = collect($sessions)
            ->where('status', 'LOGGED_IN')
            ->count();
        $this->info(
            'TelegramApiServer готов. Сессий: '.count($sessions).", авторизовано: {$loggedIn}.",
        );

        return self::SUCCESS;
    }
}
