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
    private bool $stopRequested = false;

    public function handle(ReconcileTelegramCollectors $reconcileTelegramCollectors): int
    {
        $this->stopRequested = false;
        $this->trap([SIGINT, SIGTERM], function (): void {
            $this->stopRequested = true;
        });

        $count = $reconcileTelegramCollectors->handle(
            fn (): bool => $this->stopRequested,
        );

        if ($this->stopRequested) {
            $this->warn('Переназначение источников остановлено по сигналу.');

            return self::FAILURE;
        }

        $this->info("Переназначено источников: {$count}.");

        return self::SUCCESS;
    }
}
