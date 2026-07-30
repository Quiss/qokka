<?php

namespace App\Console\Commands;

use App\Models\TelegramOwnerCommand;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('telegram:owner:status')]
#[Description('Показать состояние команд, выполняемых владельцами Telegram-сессий')]
class TelegramOwnerStatusCommand extends Command
{
    public function handle(): int
    {
        $rows = TelegramOwnerCommand::query()
            ->selectRaw('telegram_account_id, type, status, COUNT(*) AS command_count')
            ->groupBy('telegram_account_id', 'type', 'status')
            ->orderBy('telegram_account_id')
            ->orderBy('type')
            ->orderBy('status')
            ->get();

        $this->table(
            ['Аккаунт', 'Тип', 'Статус', 'Количество'],
            $rows->map(static fn (TelegramOwnerCommand $command): array => [
                $command->telegram_account_id,
                $command->type->value,
                $command->status->value,
                (int) $command->getAttribute('command_count'),
            ])->all(),
        );

        return self::SUCCESS;
    }
}
