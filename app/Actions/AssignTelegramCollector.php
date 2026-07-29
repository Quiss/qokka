<?php

namespace App\Actions;

use App\Models\SourceChannel;
use App\Models\TelegramAccount;
use App\TelegramSourceAccessStatus;
use Illuminate\Support\Facades\Log;
use Throwable;

class AssignTelegramCollector
{
    public function __construct(
        private readonly SubscribeTelegramCollectorToSource $subscribeTelegramCollectorToSource,
    ) {}

    public function handle(
        SourceChannel $sourceChannel,
        bool $ensureCurrentSubscription = true,
        bool $retryUnavailablePreferred = false,
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

            if (
                $preferredAccount === null
                && $retryUnavailablePreferred
                && filled($sourceChannel->username)
            ) {
                $preferredAccount = TelegramAccount::query()
                    ->collectorReady()
                    ->find($sourceChannel->preferred_collector_telegram_account_id);
            }

            if ($preferredAccount !== null) {
                $accounts = $accounts
                    ->reject(fn (TelegramAccount $account): bool => $account->is($preferredAccount))
                    ->prepend($preferredAccount);
            }
        }

        foreach ($accounts as $account) {
            $isCurrentAccount = $sourceChannel->collector_telegram_account_id === $account->id;

            if ($isCurrentAccount && ! $ensureCurrentSubscription) {
                return $account;
            }

            try {
                $this->subscribeTelegramCollectorToSource->handle($account, $sourceChannel);
            } catch (Throwable $exception) {
                $sourceChannel->telegramAccounts()->syncWithoutDetaching([
                    $account->id => [
                        'access_status' => TelegramSourceAccessStatus::Unavailable->value,
                        'last_checked_at' => now(),
                        'last_error' => $exception->getMessage(),
                    ],
                ]);

                Log::warning('Telegram collector could not subscribe to source.', [
                    'source_channel_id' => $sourceChannel->id,
                    'telegram_account_id' => $account->id,
                    'error' => $exception->getMessage(),
                ]);

                continue;
            }

            $sourceChannel->telegramAccounts()->syncWithoutDetaching([
                $account->id => [
                    'access_status' => TelegramSourceAccessStatus::Available->value,
                    'last_checked_at' => now(),
                    'last_error' => null,
                ],
            ]);

            if (! $isCurrentAccount) {
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
