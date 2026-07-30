<?php

namespace App\Console\Commands;

use App\Actions\RequestMissingTelegramMedia;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('telegram:media:request-missing
    {--limit=0 : Максимальное число origin media, 0 — без ограничения}
    {--include-failed : Явно повторить ранее провалившиеся загрузки}')]
#[Description('Создать Madeline owner-команды для всех отсутствующих Telegram-файлов')]
class TelegramRequestMissingMediaCommand extends Command
{
    public function handle(RequestMissingTelegramMedia $requestMissingMedia): int
    {
        $result = $requestMissingMedia->handle(
            max(0, (int) $this->option('limit')),
            includeFailed: (bool) $this->option('include-failed'),
        );

        $this->info(
            "Создано или подтверждено owner-команд: {$result['requested']}. "
            ."Ошибок: {$result['failed']}.",
        );

        return $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
