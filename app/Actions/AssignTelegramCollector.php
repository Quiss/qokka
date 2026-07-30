<?php

namespace App\Actions;

use App\Models\SourceChannel;
use App\Models\TelegramAccount;
use App\TelegramSourceAccessStatus;

class AssignTelegramCollector
{
    public function handle(
        SourceChannel $sourceChannel,
        bool $clearWhenUnavailable = true,
    ): ?TelegramAccount {
        $accounts = $sourceChannel->telegramAccounts()
            ->wherePivot('access_status', TelegramSourceAccessStatus::Available->value)
            ->collectorReady()
            ->withCount('assignedSourceChannels')
            ->orderBy('assigned_source_channels_count')
            ->orderBy('telegram_accounts.id')
            ->get();

        if ($sourceChannel->preferred_collector_telegram_account_id !== null) {
            $preferredAccount = $accounts->firstWhere(
                'id',
                $sourceChannel->preferred_collector_telegram_account_id,
            );

            if ($preferredAccount !== null) {
                $accounts = $accounts
                    ->reject(fn (TelegramAccount $account): bool => $account->is($preferredAccount))
                    ->prepend($preferredAccount);
            }
        }

        foreach ($accounts as $account) {
            if ($sourceChannel->collector_telegram_account_id !== $account->id) {
                $sourceChannel->update([
                    'collector_telegram_account_id' => $account->id,
                ]);
            }

            return $account;
        }

        if ($clearWhenUnavailable && $sourceChannel->collector_telegram_account_id !== null) {
            $sourceChannel->update([
                'collector_telegram_account_id' => null,
            ]);
        }

        return null;
    }
}
