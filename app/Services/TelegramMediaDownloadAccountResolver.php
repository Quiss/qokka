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
            'source.collectorTelegramAccount',
            'source.telegramAccounts',
        ]);
        $source = $sourceMessage->source;
        $accounts = collect([
            $source->collectorTelegramAccount,
            $sourceMessage->telegramAccount,
        ])
            ->filter()
            ->concat($source->telegramAccounts)
            ->unique('id');
        $account = $accounts->first(
            fn (TelegramAccount $account): bool => $account->is_active
                && in_array($account->status, [
                    TelegramAccountStatus::Authorized,
                    TelegramAccountStatus::Connected,
                ], true)
                && (
                    $source->collector_telegram_account_id === $account->id
                    || $source->hasAvailableAccessFor($account->id)
                ),
        );

        if (! $account instanceof TelegramAccount) {
            return null;
        }

        if ($source->collector_telegram_account_id !== $account->id) {
            $source->update(['collector_telegram_account_id' => $account->id]);
        }

        return $account;
    }
}
