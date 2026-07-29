<?php

namespace App\Console\Commands;

use App\Models\TelegramAccount;
use App\Services\MadelineOwnerLease;
use App\TelegramAccountStatus;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('telegram:health')]
#[Description('Check the foreground MadelineProto owner and account heartbeats')]
class TelegramHealthCommand extends Command
{
    public function handle(MadelineOwnerLease $ownerLease): int
    {
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

        $unhealthyAccounts = $accounts->filter(
            fn (TelegramAccount $account): bool => ! $account->isCollectorReady()
                || ! $ownerLease->isFresh($account->uuid),
        );

        if ($unhealthyAccounts->isNotEmpty()) {
            $this->error(
                'MadelineProto owner не готов для аккаунтов: '
                .$unhealthyAccounts->pluck('name')->join(', ')
                .'.',
            );

            return self::FAILURE;
        }

        $this->info("MadelineProto owner готов. Аккаунтов: {$accounts->count()}.");

        return self::SUCCESS;
    }
}
