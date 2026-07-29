<?php

namespace App\Console\Commands;

use App\Models\TelegramAccount;
use App\Services\TelegramMediaDownloadConcurrency;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('telegram:wait-for-media-downloads {--timeout=430 : Maximum wait time in seconds}')]
#[Description('Wait until active Telegram media downloads release their account locks')]
class TelegramWaitForMediaDownloadsCommand extends Command
{
    public function handle(TelegramMediaDownloadConcurrency $downloadConcurrency): int
    {
        $timeout = max(0, (int) $this->option('timeout'));
        $deadline = microtime(true) + $timeout;

        do {
            $busyAccounts = TelegramAccount::query()
                ->where('is_active', true)
                ->orderBy('id')
                ->get()
                ->reject(
                    fn (TelegramAccount $account): bool => $downloadConcurrency->isIdle($account),
                );

            if ($busyAccounts->isEmpty()) {
                $this->info('Активные Telegram media downloads завершены.');

                return self::SUCCESS;
            }

            if (microtime(true) >= $deadline) {
                $this->error(
                    'Не дождались завершения media downloads для аккаунтов: '
                    .$busyAccounts->pluck('name')->join(', ')
                    .'.',
                );

                return self::FAILURE;
            }

            usleep(500_000);
        } while (true);
    }
}
