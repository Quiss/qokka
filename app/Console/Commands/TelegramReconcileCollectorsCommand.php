<?php

namespace App\Console\Commands;

use App\Actions\ReconcileTelegramCollectors;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('telegram:accounts:reconcile')]
#[Description('Переназначить источники между доступными Telegram-аккаунтами')]
class TelegramReconcileCollectorsCommand extends Command
{
    public function handle(ReconcileTelegramCollectors $reconcileTelegramCollectors): int
    {
        $count = $reconcileTelegramCollectors->handle();
        $this->info("Переназначено источников: {$count}.");

        return self::SUCCESS;
    }
}
