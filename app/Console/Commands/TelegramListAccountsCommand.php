<?php

namespace App\Console\Commands;

use App\Models\TelegramAccount;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('telegram:account:list')]
#[Description('Показать подключённые Telegram-аккаунты и состояние listener')]
class TelegramListAccountsCommand extends Command
{
    public function handle(): int
    {
        $rows = TelegramAccount::query()
            ->withCount('assignedSourceChannels')
            ->orderBy('id')
            ->get()
            ->map(fn (TelegramAccount $account): array => [
                $account->id,
                $account->name,
                $account->username ? '@'.$account->username : '—',
                $account->phone_hint ?? '—',
                $account->status->value,
                $account->is_active ? 'да' : 'нет',
                $account->last_seen_at?->diffForHumans() ?? 'никогда',
                $account->assigned_source_channels_count,
                $account->last_error ?? '—',
            ]);

        $this->table(
            ['ID', 'Имя', 'Username', 'Телефон', 'Статус', 'Активен', 'Heartbeat', 'Источники', 'Ошибка'],
            $rows,
        );

        return self::SUCCESS;
    }
}
