<?php

namespace App\Console\Commands;

use App\Models\TelegramAccount;
use App\Services\MadelineApiFactory;
use App\TelegramAccountStatus;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

#[Signature('telegram:account:authorize {name : Понятное имя аккаунта}')]
#[Description('Подключить или повторно авторизовать Telegram-аккаунт MadelineProto')]
class TelegramAuthorizeAccountCommand extends Command
{
    public function handle(MadelineApiFactory $apiFactory): int
    {
        $name = trim((string) $this->argument('name'));
        $account = TelegramAccount::query()->firstOrCreate(
            ['name' => $name],
            ['status' => TelegramAccountStatus::Pending],
        );

        $this->info('Откройте Telegram на телефоне и отсканируйте QR-код.');
        $this->line('Если QR недоступен, MadelineProto предложит вход по телефону и коду.');

        try {
            $api = $apiFactory->make($account);
            $api->start();
            $self = $api->getSelf();

            if (! is_array($self)) {
                throw new RuntimeException('MadelineProto не вернул данные авторизованного аккаунта.');
            }

            $phone = isset($self['phone']) ? (string) $self['phone'] : null;
            $account->update([
                'telegram_user_id' => (int) $self['id'],
                'username' => $self['username'] ?? null,
                'phone_hint' => $phone === null ? null : '***'.substr($phone, -4),
                'status' => TelegramAccountStatus::Authorized,
                'is_active' => true,
                'authorized_at' => now(),
                'last_error' => null,
            ]);
        } catch (Throwable $exception) {
            $account->update([
                'status' => TelegramAccountStatus::Error,
                'last_error' => $exception->getMessage(),
            ]);
            $this->error('Авторизация не завершена: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Аккаунт «{$account->name}» подключён. Перезапустите telegram:listen.");

        return self::SUCCESS;
    }
}
