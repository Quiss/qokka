<?php

namespace App\Console\Commands;

use App\Models\TelegramAccount;
use App\Services\MadelineApiFactory;
use App\Services\MadelineListenerSupervisor;
use App\Services\MadelineProtoListenerSession;
use App\Telegram\ChannelSourceEventHandler;
use App\TelegramAccountStatus;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('telegram:listen')]
#[Description('Run the MadelineProto user-session listener for source channels')]
class TelegramListenCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(
        MadelineApiFactory $apiFactory,
        MadelineListenerSupervisor $listenerSupervisor,
    ): int {
        if ((string) config('services.telegram.bridge_secret') === '') {
            $this->error('TELEGRAM_BRIDGE_SECRET не настроен. Укажите общий секрет для listener и HTTP bridge.');

            return self::FAILURE;
        }

        $accounts = TelegramAccount::query()
            ->where('is_active', true)
            ->whereIn('status', [TelegramAccountStatus::Authorized, TelegramAccountStatus::Connected])
            ->orderBy('id')
            ->get();

        if ($accounts->isEmpty()) {
            $this->error('Нет авторизованных аккаунтов. Выполните telegram:account:authorize {name}.');

            return self::FAILURE;
        }

        $sessions = $accounts
            ->mapWithKeys(fn (TelegramAccount $account): array => [
                $account->uuid => new MadelineProtoListenerSession($apiFactory->make($account)),
            ])
            ->all();
        $remoteAccounts = $accounts
            ->filter(fn (TelegramAccount $account): bool => $sessions[$account->uuid]->isRemote());

        $this->info("Запускаю Telegram listener для аккаунтов: {$accounts->pluck('name')->join(', ')}.");

        if ($remoteAccounts->isNotEmpty()) {
            $this->warn(
                'Передаю EventHandler из IPC worker в контейнер madeline для аккаунтов: '
                .$remoteAccounts->pluck('name')->join(', ')
                .'.',
            );
        }

        $listenerSupervisor->run($sessions, ChannelSourceEventHandler::class);
        $this->error('Telegram listener неожиданно остановился.');

        return self::FAILURE;
    }
}
