<?php

namespace App\Console\Commands;

use App\Models\TelegramAccount;
use App\Services\MadelineOwnerLease;
use App\Services\TelegramApiServer;
use App\Services\TelegramApiServerClientFactory;
use App\Services\TelegramOwnerCommandPump;
use App\TelegramAccountStatus;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

use function Amp\async;
use function Amp\delay;
use function Amp\Future\awaitFirst;

#[Signature('telegram:owner:work')]
#[Description('Execute Telegram owner commands through TelegramApiServer')]
class TelegramOwnerWorkCommand extends Command
{
    public function handle(
        TelegramApiServer $server,
        TelegramApiServerClientFactory $clientFactory,
        TelegramOwnerCommandPump $commandPump,
        MadelineOwnerLease $ownerLease,
    ): int {
        while (true) {
            try {
                $sessions = $server->sessions();
            } catch (Throwable $exception) {
                $this->warn('TelegramApiServer пока недоступен: '.$exception->getMessage());
                delay(5);

                continue;
            }

            $accounts = TelegramAccount::query()
                ->where('is_active', true)
                ->whereIn('status', [
                    TelegramAccountStatus::Authorized,
                    TelegramAccountStatus::Connected,
                ])
                ->orderBy('id')
                ->get()
                ->filter(
                    fn (TelegramAccount $account): bool => ($sessions[$account->uuid]['status'] ?? null) === 'LOGGED_IN',
                );

            if ($accounts->isEmpty()) {
                $this->warn(
                    'Нет авторизованных сессий TelegramApiServer. '
                    .'Выполните telegram:account:authorize {name}.',
                );
                delay(5);

                continue;
            }

            $this->info(
                'Запускаю Telegram owner worker для аккаунтов: '
                .$accounts->pluck('name')->join(', ')
                .'.',
            );
            $futures = $accounts
                ->map(
                    fn (TelegramAccount $account) => async(
                        function () use ($account, $clientFactory, $commandPump, $ownerLease): void {
                            try {
                                $commandPump->run(
                                    $account->uuid,
                                    $clientFactory->forAccount($account),
                                );
                            } finally {
                                $ownerLease->release($account->uuid);
                            }
                        },
                    ),
                )
                ->all();

            awaitFirst($futures);

            throw new \RuntimeException('Telegram owner command pump остановился неожиданно.');
        }
    }
}
