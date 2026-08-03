<?php

namespace App\Actions;

use App\Models\Source;
use App\Models\TelegramAccount;
use App\TelegramSourceAccessStatus;

class AssignTelegramCollector
{
    public function handle(
        Source $source,
        bool $clearWhenUnavailable = true,
    ): ?TelegramAccount {
        if (! $source->isTelegram()) {
            return null;
        }

        $accounts = $source->telegramAccounts()
            ->wherePivot('access_status', TelegramSourceAccessStatus::Available->value)
            ->collectorReady()
            ->withCount('assignedSourceChannels')
            ->orderBy('assigned_source_channels_count')
            ->orderBy('telegram_accounts.id')
            ->get();

        if ($source->preferred_collector_telegram_account_id !== null) {
            $preferredAccount = $accounts->firstWhere(
                'id',
                $source->preferred_collector_telegram_account_id,
            );

            if ($preferredAccount !== null) {
                $accounts = $accounts
                    ->reject(fn (TelegramAccount $account): bool => $account->is($preferredAccount))
                    ->prepend($preferredAccount);
            }
        }

        foreach ($accounts as $account) {
            if ($source->collector_telegram_account_id !== $account->id) {
                $source->update([
                    'collector_telegram_account_id' => $account->id,
                ]);
            }

            return $account;
        }

        if ($clearWhenUnavailable && $source->collector_telegram_account_id !== null) {
            $source->update([
                'collector_telegram_account_id' => null,
            ]);
        }

        return null;
    }
}
