<?php

namespace App\Console\Commands;

use App\Actions\FindDeletedTelegramChannelParticipants;
use App\Contracts\MadelineClient;
use App\Models\SourceChannel;
use App\Models\TelegramAccount;
use App\Services\MadelineClientPool;
use App\TelegramAccountStatus;
use danog\MadelineProto\RPCErrorException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

#[Signature('telegram:channel:clean-deleted
    {channel : Username канала, например @pokatrend}
    {--account= : ID, имя или username подключённого MadelineProto-аккаунта}
    {--force : Не запрашивать подтверждение}
    {--dry-run : Только найти удалённые аккаунты, ничего не удаляя}')]
#[Description('Найти и удалить деактивированные Telegram-аккаунты из канала')]
class TelegramCleanDeletedChannelMembersCommand extends Command
{
    public function handle(
        MadelineClientPool $clientPool,
        FindDeletedTelegramChannelParticipants $findDeletedParticipants,
    ): int {
        if ($this->option('force') && $this->option('dry-run')) {
            $this->error('Параметры --force и --dry-run нельзя использовать одновременно.');

            return self::FAILURE;
        }

        $telegramAccount = $this->resolveTelegramAccount();

        if ($telegramAccount === null) {
            return self::FAILURE;
        }

        $username = SourceChannel::normalizeUsername((string) $this->argument('channel'));

        if (blank($username)) {
            $this->error('Укажите публичный username канала.');

            return self::FAILURE;
        }

        $channel = '@'.$username;

        try {
            $client = $clientPool->forAccount($telegramAccount);

            if (! $client->canBanChannelParticipants($channel)) {
                $this->error(
                    "Аккаунт {$this->telegramAccountLabel($telegramAccount)} "
                    ."не имеет права блокировать участников {$channel}.",
                );
                Log::warning('Telegram channel cleanup permission denied.', [
                    'channel' => $channel,
                    'telegram_account_id' => $telegramAccount->id,
                ]);

                return self::FAILURE;
            }

            $deletedParticipants = $findDeletedParticipants->handle($client, $channel);
        } catch (Throwable $exception) {
            $clientPool->forget($telegramAccount);
            $this->error('Не удалось проверить участников канала: '.$this->telegramError($exception));
            Log::warning('Telegram deleted channel participant scan failed.', [
                'channel' => $channel,
                'telegram_account_id' => $telegramAccount->id,
                'error' => $exception->getMessage(),
            ]);

            return self::FAILURE;
        }

        $found = count($deletedParticipants);
        $this->info("Канал: {$channel}. Найдено удалённых аккаунтов: {$found}.");

        if ($found === 0) {
            $this->logSummary($channel, $telegramAccount, [
                'found' => 0,
                'removed' => 0,
                'left_banned' => 0,
                'failed' => 0,
                'mode' => 'empty',
            ]);

            return self::SUCCESS;
        }

        $this->showPreview($deletedParticipants);

        if ($this->option('dry-run')) {
            $this->info('Режим dry-run: изменения в Telegram не выполнялись.');
            $this->logSummary($channel, $telegramAccount, [
                'found' => $found,
                'removed' => 0,
                'left_banned' => 0,
                'failed' => 0,
                'mode' => 'dry-run',
            ]);

            return self::SUCCESS;
        }

        if (
            ! $this->option('force')
            && ! $this->confirm(
                "Удалить найденные деактивированные аккаунты ({$found}) из {$channel} "
                .'и затем снять с них блокировку?',
            )
        ) {
            $this->info('Очистка отменена.');
            $this->logSummary($channel, $telegramAccount, [
                'found' => $found,
                'removed' => 0,
                'left_banned' => 0,
                'failed' => 0,
                'mode' => 'cancelled',
            ]);

            return self::SUCCESS;
        }

        return $this->removeParticipants(
            $client,
            $clientPool,
            $telegramAccount,
            $channel,
            $deletedParticipants,
        );
    }

    private function resolveTelegramAccount(): ?TelegramAccount
    {
        $eligibleAccounts = TelegramAccount::query()
            ->where('is_active', true)
            ->whereIn('status', [
                TelegramAccountStatus::Authorized->value,
                TelegramAccountStatus::Connected->value,
            ])
            ->orderBy('id');
        $selector = trim((string) $this->option('account'));

        if ($selector !== '') {
            $accounts = $this->matchingAccounts($eligibleAccounts, $selector)->get();

            if ($accounts->count() === 1) {
                return $accounts->first();
            }

            $this->error($accounts->isEmpty()
                ? "Активный авторизованный Telegram-аккаунт «{$selector}» не найден."
                : "Параметр --account={$selector} соответствует нескольким аккаунтам.");

            return null;
        }

        $accounts = $eligibleAccounts->get();

        if ($accounts->count() === 1) {
            return $accounts->first();
        }

        if ($accounts->isEmpty()) {
            $this->error('Нет активных авторизованных Telegram-аккаунтов.');

            return null;
        }

        $this->table(
            ['ID', 'Имя', 'Username'],
            $accounts->map(fn (TelegramAccount $account): array => [
                $account->id,
                $account->name,
                $account->username ? '@'.$account->username : '—',
            ])->all(),
        );
        $this->error('Найдено несколько аккаунтов. Укажите нужный через --account=');

        return null;
    }

    /**
     * @param  Builder<TelegramAccount>  $query
     * @return Builder<TelegramAccount>
     */
    private function matchingAccounts(Builder $query, string $selector): Builder
    {
        if (ctype_digit($selector)) {
            return $query->whereKey((int) $selector);
        }

        $username = Str::of($selector)->trim()->ltrim('@')->lower()->toString();

        return $query->where(function (Builder $query) use ($selector, $username): void {
            $query->where('name', $selector)
                ->orWhereRaw('LOWER(username) = ?', [$username]);
        });
    }

    /** @param list<array<string, mixed>> $deletedParticipants */
    private function showPreview(array $deletedParticipants): void
    {
        $preview = array_slice($deletedParticipants, 0, 20);
        $this->table(
            ['#', 'Telegram user ID'],
            array_map(
                fn (array $participant, int $index): array => [
                    $index + 1,
                    (int) $participant['id'],
                ],
                $preview,
                array_keys($preview),
            ),
        );

        if (count($deletedParticipants) > count($preview)) {
            $remaining = count($deletedParticipants) - count($preview);
            $this->line("Ещё аккаунтов: {$remaining}.");
        }
    }

    /**
     * @param  list<array<string, mixed>>  $deletedParticipants
     */
    private function removeParticipants(
        MadelineClient $client,
        MadelineClientPool $clientPool,
        TelegramAccount $telegramAccount,
        string $channel,
        array $deletedParticipants,
    ): int {
        $removed = 0;
        $leftBanned = 0;
        $failed = 0;
        $aborted = false;
        $progressBar = $this->output->createProgressBar(count($deletedParticipants));
        $progressBar->start();

        foreach ($deletedParticipants as $participant) {
            $participantId = (int) $participant['id'];

            try {
                $client->banChannelParticipant($channel, $participantId);
            } catch (Throwable $exception) {
                if ($this->isAlreadyAbsent($exception)) {
                    $removed++;
                    $progressBar->advance();

                    continue;
                }

                $failed++;
                $this->newLine();
                $this->warn(
                    "Не удалось удалить Telegram user ID {$participantId}: "
                    .$this->telegramError($exception),
                );
                $progressBar->advance();

                if ($this->mustAbort($exception)) {
                    $aborted = true;
                    $clientPool->forget($telegramAccount);

                    break;
                }

                continue;
            }

            try {
                $client->unbanChannelParticipant($channel, $participantId);
                $removed++;
            } catch (Throwable $exception) {
                if ($this->isAlreadyAbsent($exception)) {
                    $removed++;
                } else {
                    $leftBanned++;
                    $this->newLine();
                    $this->warn(
                        "Аккаунт {$participantId} удалён, но снять блокировку не удалось: "
                        .$this->telegramError($exception),
                    );

                    if ($this->mustAbort($exception)) {
                        $aborted = true;
                        $clientPool->forget($telegramAccount);
                    }
                }
            }

            $progressBar->advance();

            if ($aborted) {
                break;
            }
        }

        $progressBar->finish();
        $this->newLine(2);

        $found = count($deletedParticipants);
        $this->info(
            "Итог: найдено {$found}, удалено и разблокировано {$removed}, "
            ."осталось в бан-листе {$leftBanned}, ошибок {$failed}.",
        );

        if ($aborted) {
            $this->error('Очистка остановлена из-за критической ошибки Telegram.');
        }

        $this->logSummary($channel, $telegramAccount, [
            'found' => $found,
            'removed' => $removed,
            'left_banned' => $leftBanned,
            'failed' => $failed,
            'aborted' => $aborted,
            'mode' => 'apply',
        ]);

        return $aborted || $leftBanned > 0 || $failed > 0
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function isAlreadyAbsent(Throwable $exception): bool
    {
        return $exception instanceof RPCErrorException
            && $exception->rpc === 'USER_NOT_PARTICIPANT';
    }

    private function mustAbort(Throwable $exception): bool
    {
        if (! $exception instanceof RPCErrorException) {
            return true;
        }

        return Str::startsWith($exception->rpc, [
            'FLOOD_WAIT_',
            'FLOOD_PREMIUM_WAIT_',
        ]) || in_array($exception->rpc, [
            'AUTH_KEY_UNREGISTERED',
            'CHANNEL_INVALID',
            'CHANNEL_PRIVATE',
            'CHAT_ADMIN_REQUIRED',
            'CHAT_WRITE_FORBIDDEN',
            'SESSION_REVOKED',
            'USER_ADMIN_INVALID',
        ], true);
    }

    private function telegramError(Throwable $exception): string
    {
        if ($exception instanceof RPCErrorException) {
            return $exception->rpc.' — '.$exception->description;
        }

        return $exception->getMessage();
    }

    private function telegramAccountLabel(TelegramAccount $telegramAccount): string
    {
        return filled($telegramAccount->username)
            ? '@'.$telegramAccount->username
            : "«{$telegramAccount->name}»";
    }

    /** @param array<string, mixed> $summary */
    private function logSummary(
        string $channel,
        TelegramAccount $telegramAccount,
        array $summary,
    ): void {
        Log::info('Telegram deleted channel participant cleanup finished.', [
            'channel' => $channel,
            'telegram_account_id' => $telegramAccount->id,
            ...$summary,
        ]);
    }
}
