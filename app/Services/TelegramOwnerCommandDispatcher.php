<?php

namespace App\Services;

use App\Models\TelegramAccount;
use App\Models\TelegramOwnerCommand;
use App\TelegramOwnerCommandStatus;
use App\TelegramOwnerCommandType;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TelegramOwnerCommandDispatcher
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function dispatch(
        TelegramAccount $telegramAccount,
        TelegramOwnerCommandType $type,
        array $payload,
        string $deduplicationKey,
        int $priority = 0,
        int $maxAttempts = 3,
    ): TelegramOwnerCommand {
        $store = Cache::store(
            (string) config('services.telegram.coordination_cache_store', 'redis'),
        )->getStore();

        if (! $store instanceof LockProvider) {
            throw new RuntimeException('Telegram owner command cache store does not support locks.');
        }

        $lock = $store->lock(
            'telegram:owner-command:'.$telegramAccount->id.':'.sha1($deduplicationKey),
            10,
        );

        try {
            return $lock->block(5, function () use (
                $telegramAccount,
                $type,
                $payload,
                $deduplicationKey,
                $priority,
                $maxAttempts,
            ): TelegramOwnerCommand {
                return DB::transaction(function () use (
                    $telegramAccount,
                    $type,
                    $payload,
                    $deduplicationKey,
                    $priority,
                    $maxAttempts,
                ): TelegramOwnerCommand {
                    $normalizedMaxAttempts = max(1, $maxAttempts);
                    $command = TelegramOwnerCommand::query()
                        ->whereBelongsTo($telegramAccount)
                        ->where('deduplication_key', $deduplicationKey)
                        ->lockForUpdate()
                        ->first();

                    if (
                        $command !== null
                        && in_array($command->status, [
                            TelegramOwnerCommandStatus::Pending,
                            TelegramOwnerCommandStatus::Running,
                        ], true)
                    ) {
                        if ($command->max_attempts < $normalizedMaxAttempts) {
                            $command->update([
                                'max_attempts' => $normalizedMaxAttempts,
                            ]);
                        }

                        return $command;
                    }

                    $command ??= new TelegramOwnerCommand([
                        'telegram_account_id' => $telegramAccount->id,
                        'deduplication_key' => $deduplicationKey,
                    ]);
                    $command->fill([
                        'type' => $type,
                        'status' => TelegramOwnerCommandStatus::Pending,
                        'payload' => $payload,
                        'result' => null,
                        'priority' => $priority,
                        'attempts' => 0,
                        'max_attempts' => $normalizedMaxAttempts,
                        'available_at' => now(),
                        'started_at' => null,
                        'finished_at' => null,
                        'last_error' => null,
                    ]);
                    $command->save();

                    return $command;
                });
            });
        } catch (LockTimeoutException) {
            return TelegramOwnerCommand::query()
                ->whereBelongsTo($telegramAccount)
                ->where('deduplication_key', $deduplicationKey)
                ->firstOrFail();
        }
    }
}
