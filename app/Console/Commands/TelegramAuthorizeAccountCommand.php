<?php

namespace App\Console\Commands;

use App\Models\TelegramAccount;
use App\Services\MadelineOwnerLease;
use App\Services\TelegramApiServer;
use App\TelegramAccountStatus;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

#[Signature('telegram:account:authorize {name : Понятное имя аккаунта}')]
#[Description('Подключить или повторно авторизовать Telegram-аккаунт через TelegramApiServer')]
class TelegramAuthorizeAccountCommand extends Command
{
    public function handle(
        TelegramApiServer $server,
        MadelineOwnerLease $ownerLease,
    ): int {
        $name = trim((string) $this->argument('name'));
        $account = TelegramAccount::query()->firstOrCreate(
            ['name' => $name],
            ['status' => TelegramAccountStatus::Pending],
        );

        if ($ownerLease->isFresh($account->uuid)) {
            $this->error(
                'Аккаунт сейчас обрабатывается telegram-owner. '
                .'Остановите контейнер telegram-owner перед повторной авторизацией.',
            );

            return self::FAILURE;
        }

        try {
            $status = $server->sessionStatus($account->uuid);

            if ($status === null) {
                $server->addSession($account->uuid);
                $status = $server->sessionStatus($account->uuid);
            }

            if ($status !== 'LOGGED_IN') {
                $phone = trim((string) $this->ask('Номер телефона в международном формате (+...)'));

                if ($phone === '') {
                    throw new RuntimeException('Номер телефона не указан.');
                }

                $server->call($account->uuid, 'phoneLogin', ['number' => $phone]);
                $code = trim((string) $this->secret('Код подтверждения из Telegram'));

                if ($code === '') {
                    throw new RuntimeException('Код подтверждения не указан.');
                }

                $server->call($account->uuid, 'completePhoneLogin', ['code' => $code]);

                if ($server->sessionStatus($account->uuid) === 'WAITING_PASSWORD') {
                    $password = (string) $this->secret('Пароль двухфакторной аутентификации');
                    $server->call($account->uuid, 'complete2faLogin', ['password' => $password]);
                }
            }

            if (! $server->isLoggedIn($account->uuid)) {
                throw new RuntimeException('TelegramApiServer не завершил авторизацию сессии.');
            }

            $server->call($account->uuid, 'serialize');
            $self = $server->call($account->uuid, 'getSelf');
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

        $this->info(
            "Аккаунт «{$account->name}» подключён. "
            .'Перезапустите telegram-events и telegram-owner.',
        );

        return self::SUCCESS;
    }
}
