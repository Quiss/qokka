<?php

namespace App\Services;

use App\Actions\QueueOperationsNotification;
use App\Actions\RequestMissingTelegramMedia;
use App\Contracts\MadelineClient;
use App\Exceptions\PermanentTelegramMediaException;
use App\Models\TelegramAccount;
use App\Models\TelegramOwnerCommand;
use App\OperationsNotificationTopic;
use App\TelegramOwnerCommandStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

use function Amp\delay;

class TelegramOwnerCommandPump
{
    private const array BackoffSeconds = [60, 300, 900];

    public function __construct(
        private readonly TelegramOwnerCommandExecutor $executor,
        private readonly QueueOperationsNotification $queueOperationsNotification,
        private readonly RequestMissingTelegramMedia $requestMissingMedia,
    ) {}

    public function run(string $telegramAccountUuid, MadelineClient $client): never
    {
        $telegramAccount = TelegramAccount::query()
            ->where('uuid', $telegramAccountUuid)
            ->firstOrFail();

        TelegramOwnerCommand::query()
            ->whereBelongsTo($telegramAccount)
            ->where('status', TelegramOwnerCommandStatus::Running)
            ->update([
                'status' => TelegramOwnerCommandStatus::Pending,
                'available_at' => now(),
                'started_at' => null,
            ]);
        $nextBacklogScanAt = 0;

        while (true) {
            if (time() >= $nextBacklogScanAt) {
                $this->requestMissingMediaWithoutStoppingPump($telegramAccount);
                $nextBacklogScanAt = time() + 60;
            }

            try {
                $command = $this->claim($telegramAccount);
            } catch (Throwable $exception) {
                Log::error('Telegram owner command pump could not claim a command.', [
                    'telegram_account_id' => $telegramAccount->id,
                    'error' => $exception->getMessage(),
                ]);
                delay(5);

                continue;
            }

            if ($command === null) {
                delay(0.5);

                continue;
            }

            $this->execute($command, $client);
        }
    }

    private function requestMissingMediaWithoutStoppingPump(
        TelegramAccount $telegramAccount,
    ): void {
        try {
            $result = $this->requestMissingMedia->handle(throttled: true);

            if (! $result['skipped'] && ($result['requested'] > 0 || $result['failed'] > 0)) {
                Log::info('Telegram missing media backlog was requested by owner.', [
                    'telegram_account_id' => $telegramAccount->id,
                    'requested' => $result['requested'],
                    'failed' => $result['failed'],
                ]);
            }
        } catch (Throwable $exception) {
            Log::error('Telegram owner could not scan the missing media backlog.', [
                'telegram_account_id' => $telegramAccount->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function claim(TelegramAccount $telegramAccount): ?TelegramOwnerCommand
    {
        return DB::transaction(function () use ($telegramAccount): ?TelegramOwnerCommand {
            $command = TelegramOwnerCommand::query()
                ->whereBelongsTo($telegramAccount)
                ->where('status', TelegramOwnerCommandStatus::Pending)
                ->where('available_at', '<=', now())
                ->orderByDesc('priority')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if ($command === null) {
                return null;
            }

            $command->update([
                'status' => TelegramOwnerCommandStatus::Running,
                'attempts' => $command->attempts + 1,
                'started_at' => now(),
                'finished_at' => null,
            ]);

            return $command->fresh();
        });
    }

    private function execute(TelegramOwnerCommand $command, MadelineClient $client): void
    {
        try {
            $result = $this->executor->execute($command, $client);
            $command->update([
                'status' => TelegramOwnerCommandStatus::Completed,
                'result' => $result,
                'last_error' => null,
                'finished_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $this->executor->recordFailure($command, $exception);
            $terminal = $exception instanceof PermanentTelegramMediaException
                || $command->attempts >= $command->max_attempts;

            if (! $terminal) {
                $delay = self::BackoffSeconds[min(
                    $command->attempts - 1,
                    count(self::BackoffSeconds) - 1,
                )];
                $command->update([
                    'status' => TelegramOwnerCommandStatus::Pending,
                    'available_at' => now()->addSeconds($delay),
                    'last_error' => $exception->getMessage(),
                    'started_at' => null,
                ]);
            } else {
                $command->update([
                    'status' => TelegramOwnerCommandStatus::Failed,
                    'last_error' => $exception->getMessage(),
                    'finished_at' => now(),
                ]);
                $this->notifyTerminalFailureWithoutStoppingPump($command, $exception);
            }

            Log::error('Telegram owner command failed.', [
                'telegram_owner_command_id' => $command->id,
                'telegram_account_id' => $command->telegram_account_id,
                'type' => $command->type->value,
                'attempt' => $command->attempts,
                'terminal' => $terminal,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function notifyTerminalFailureWithoutStoppingPump(
        TelegramOwnerCommand $command,
        Throwable $exception,
    ): void {
        try {
            $this->queueOperationsNotification->handle(
                OperationsNotificationTopic::Failures,
                'Терминальный сбой Telegram owner command',
                [
                    'Команда: #'.$command->id.' '.$command->type->value,
                    'Аккаунт: #'.$command->telegram_account_id,
                    'Ошибка: '.$exception->getMessage(),
                ],
                route('horizon.index', ['view' => 'failed']),
            );
        } catch (Throwable $notificationException) {
            Log::error('Telegram owner command failure notification could not be queued.', [
                'telegram_owner_command_id' => $command->id,
                'error' => $notificationException->getMessage(),
            ]);
        }
    }
}
