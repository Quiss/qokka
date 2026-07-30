<?php

namespace App\Console\Commands;

use App\Models\TelegramOwnerCommand;
use App\TelegramOwnerCommandStatus;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('telegram:owner:retry-failed {--id= : ID owner-команды} {--account= : ID Telegram-аккаунта} {--type= : Тип owner-команды}')]
#[Description('Повторно поставить терминально завершившиеся owner-команды')]
class TelegramOwnerRetryFailedCommand extends Command
{
    public function handle(): int
    {
        $query = TelegramOwnerCommand::query()
            ->where('status', TelegramOwnerCommandStatus::Failed);

        if (filled($this->option('id'))) {
            $query->whereKey((int) $this->option('id'));
        }

        if (filled($this->option('account'))) {
            $query->where('telegram_account_id', (int) $this->option('account'));
        }

        if (filled($this->option('type'))) {
            $query->where('type', (string) $this->option('type'));
        }

        $count = $query->update([
            'status' => TelegramOwnerCommandStatus::Pending,
            'attempts' => 0,
            'available_at' => now(),
            'started_at' => null,
            'finished_at' => null,
        ]);
        $this->info("Повторно поставлено owner-команд: {$count}.");

        return self::SUCCESS;
    }
}
