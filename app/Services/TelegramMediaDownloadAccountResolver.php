<?php

namespace App\Services;

use App\Models\SourceMessage;
use App\Models\TelegramAccount;
use App\TelegramAccountStatus;

class TelegramMediaDownloadAccountResolver
{
    public function resolve(SourceMessage $sourceMessage): ?TelegramAccount
    {
        $sourceMessage->loadMissing([
            'telegramAccount',
            'sourceChannel.collectorTelegramAccount',
            'sourceChannel.telegramAccounts',
        ]);
        $sourceChannel = $sourceMessage->sourceChannel;
        $accounts = collect([
            $sourceChannel->collectorTelegramAccount,
            $sourceMessage->telegramAccount,
        ])
            ->filter()
            ->concat($sourceChannel->telegramAccounts)
            ->unique('id');
        $account = $accounts->first(
            fn (TelegramAccount $account): bool => $account->is_active
                && in_array($account->status, [
                    TelegramAccountStatus::Authorized,
                    TelegramAccountStatus::Connected,
                ], true)
                && (
                    $sourceChannel->collector_telegram_account_id === $account->id
                    || $sourceChannel->hasAvailableAccessFor($account->id)
                ),
        );

        if (! $account instanceof TelegramAccount) {
            return null;
        }

        if ($sourceChannel->collector_telegram_account_id !== $account->id) {
            $sourceChannel->update(['collector_telegram_account_id' => $account->id]);
        }

        return $account;
    }
}
