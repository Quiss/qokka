<?php

namespace App\Actions;

use App\Models\SourceChannel;
use App\Models\TelegramAccount;
use App\TelegramAccountStatus;
use App\TelegramSourceAccessStatus;
use Illuminate\Database\Eloquent\Builder;

class AssignTelegramCollector
{
    public function handle(SourceChannel $sourceChannel): ?TelegramAccount
    {
        $account = $sourceChannel->telegramAccounts()
            ->wherePivot('access_status', TelegramSourceAccessStatus::Available->value)
            ->where('is_active', true)
            ->where(function (Builder $query): void {
                $query
                    ->where(function (Builder $query): void {
                        $query->where('status', TelegramAccountStatus::Connected)
                            ->where('last_seen_at', '>=', now()->subMinutes(3));
                    })
                    ->orWhere(function (Builder $query): void {
                        $query->where('status', TelegramAccountStatus::Authorized)
                            ->whereNull('last_seen_at');
                    });
            })
            ->withCount('assignedSourceChannels')
            ->orderBy('assigned_source_channels_count')
            ->orderBy('telegram_accounts.id')
            ->first();

        $sourceChannel->update([
            'collector_telegram_account_id' => $account?->id,
        ]);

        return $account;
    }
}
