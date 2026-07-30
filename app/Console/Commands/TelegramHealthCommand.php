<?php

namespace App\Console\Commands;

use App\Models\TelegramAccount;
use App\Services\MadelineOwnerLease;
use App\Services\TelegramApiServer;
use App\TelegramAccountStatus;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('telegram:health')]
#[Description('Check TelegramApiServer sessions and owner worker heartbeats')]
class TelegramHealthCommand extends Command
{
    public function handle(
        MadelineOwnerLease $ownerLease,
        TelegramApiServer $server,
    ): int {
        $accounts = TelegramAccount::query()
            ->where('is_active', true)
            ->whereIn('status', [
                TelegramAccountStatus::Authorized,
                TelegramAccountStatus::Connected,
            ])
            ->orderBy('id')
            ->get();

        if ($accounts->isEmpty()) {
            $this->error('Нет активных авторизованных Telegram-аккаунтов.');

            return self::FAILURE;
        }

        try {
            $sessions = $server->sessions();
        } catch (\Throwable $exception) {
            $this->error('TelegramApiServer недоступен: '.$exception->getMessage());

            return self::FAILURE;
        }

        $unhealthyAccounts = $accounts->filter(
            fn (TelegramAccount $account): bool => ($sessions[$account->uuid]['status'] ?? null) !== 'LOGGED_IN'
                || ! $account->isCollectorReady()
                || ! $ownerLease->isFresh($account->uuid),
        );

        if ($unhealthyAccounts->isNotEmpty()) {
            $this->error(
                'Telegram owner не готов для аккаунтов: '
                .$unhealthyAccounts->pluck('name')->join(', ')
                .'.',
            );

            return self::FAILURE;
        }

        $this->info("TelegramApiServer и owner worker готовы. Аккаунтов: {$accounts->count()}.");

        return self::SUCCESS;
    }
}
