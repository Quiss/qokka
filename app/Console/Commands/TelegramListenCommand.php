<?php

namespace App\Console\Commands;

use App\Exceptions\MadelineOwnerUnavailableException;
use App\Models\TelegramAccount;
use App\Services\MadelineApiFactory;
use App\Services\MadelineConnectionRetrier;
use App\Services\MadelineListenerSupervisor;
use App\Services\MadelineOwnerLease;
use App\Services\MadelineProtoListenerSession;
use App\Telegram\ChannelSourceEventHandler;
use App\TelegramAccountStatus;
use danog\MadelineProto\API;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('telegram:listen')]
#[Description('Run the MadelineProto user-session listener for source channels')]
class TelegramListenCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(
        MadelineApiFactory $apiFactory,
        MadelineConnectionRetrier $connectionRetrier,
        MadelineListenerSupervisor $listenerSupervisor,
        MadelineOwnerLease $ownerLease,
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

        $this->info("Запускаю Telegram listener для аккаунтов: {$accounts->pluck('name')->join(', ')}.");

        $connectionRetrier->run(
            function () use ($accounts, $apiFactory, $listenerSupervisor, $ownerLease): void {
                $sessions = $accounts
                    ->mapWithKeys(fn (TelegramAccount $account): array => [
                        $account->uuid => new MadelineProtoListenerSession(
                            $apiFactory->makeOwner($account),
                            $account->uuid,
                            $ownerLease,
                        ),
                    ])
                    ->all();
                $remoteAccounts = $accounts
                    ->filter(fn (TelegramAccount $account): bool => $sessions[$account->uuid]->isRemote());

                if ($remoteAccounts->isNotEmpty()) {
                    $this->warn(
                        'Передаю EventHandler из IPC worker в контейнер madeline для аккаунтов: '
                        .$remoteAccounts->pluck('name')->join(', ')
                        .'.',
                    );
                }

                try {
                    $listenerSupervisor->run($sessions, ChannelSourceEventHandler::class);

                    throw new MadelineOwnerUnavailableException(
                        'MadelineProto listener loop stopped unexpectedly.',
                    );
                } finally {
                    $accounts->each(function (TelegramAccount $account) use ($ownerLease): void {
                        $ownerLease->release($account->uuid);
                    });
                    unset($sessions);
                    gc_collect_cycles();
                    API::finalize();
                }
            },
            function (Throwable $exception, int $retryAttempt, int $delay) use ($connectionRetrier): void {
                $this->warn(
                    "Временная ошибка подключения MadelineProto: {$connectionRetrier->reason($exception)} "
                    ."Повтор №{$retryAttempt} через {$delay} сек.",
                );
            },
        );

        $this->error('Telegram listener неожиданно остановился.');

        return self::FAILURE;
    }
}
