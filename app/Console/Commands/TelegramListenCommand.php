<?php

namespace App\Console\Commands;

use App\Models\TelegramAccount;
use App\Services\MadelineSettingsFactory;
use App\Telegram\ChannelSourceEventHandler;
use App\TelegramAccountStatus;
use danog\MadelineProto\API;
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
    public function handle(MadelineSettingsFactory $settingsFactory): int
    {
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

        $instances = $accounts
            ->mapWithKeys(fn (TelegramAccount $account): array => [
                $account->uuid => new API(
                    $settingsFactory->sessionPath($account),
                    $settingsFactory->make($account),
                ),
            ])
            ->all();

        $this->info("Запускаю Telegram listener для аккаунтов: {$accounts->pluck('name')->join(', ')}.");
        API::startAndLoopMulti($instances, ChannelSourceEventHandler::class);

        return self::SUCCESS;
    }
}
