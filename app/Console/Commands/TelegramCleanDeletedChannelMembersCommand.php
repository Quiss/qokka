<?php

namespace App\Console\Commands;

use App\Models\SourceChannel;
use App\Models\TelegramAccount;
use App\Models\TelegramOwnerCommand;
use App\Services\TelegramOwnerCommandDispatcher;
use App\TelegramAccountStatus;
use App\TelegramOwnerCommandStatus;
use App\TelegramOwnerCommandType;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

#[Signature('telegram:channel:clean-deleted
    {channel : Username канала, например @pokatrend}
    {--account= : ID, имя или username подключённого MadelineProto-аккаунта}
    {--force : Не запрашивать подтверждение}
    {--dry-run : Только найти удалённые аккаунты, ничего не удаляя}
    {--timeout=300 : Сколько секунд ждать выполнения owner-команды}')]
#[Description('Найти и удалить деактивированные Telegram-аккаунты через Madeline owner')]
class TelegramCleanDeletedChannelMembersCommand extends Command
{
    public function handle(TelegramOwnerCommandDispatcher $commandDispatcher): int
    {
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
        $scan = $commandDispatcher->dispatch(
            $telegramAccount,
            TelegramOwnerCommandType::ScanDeletedParticipants,
            ['channel' => $channel],
            'cleanup:scan:'.Str::uuid(),
            priority: 70,
            maxAttempts: 1,
        );
        $scan = $this->waitForCommand($scan);

        if ($scan === null || $scan->status !== TelegramOwnerCommandStatus::Completed) {
            return self::FAILURE;
        }

        $participants = array_values(array_filter(
            is_array($scan->result['participants'] ?? null)
                ? $scan->result['participants']
                : [],
            is_array(...),
        ));
        $this->info("Канал: {$channel}. Найдено удалённых аккаунтов: ".count($participants).'.');

        if ($participants === []) {
            return self::SUCCESS;
        }

        $this->showPreview($participants);

        if ($this->option('dry-run')) {
            $this->info('Режим dry-run: изменения в Telegram не выполнялись.');

            return self::SUCCESS;
        }

        if (
            ! $this->option('force')
            && ! $this->confirm(
                'Удалить найденные деактивированные аккаунты ('.count($participants)
                .") из {$channel} и затем снять с них блокировку?",
            )
        ) {
            $this->info('Очистка отменена.');

            return self::SUCCESS;
        }

        $remove = $commandDispatcher->dispatch(
            $telegramAccount,
            TelegramOwnerCommandType::RemoveDeletedParticipants,
            ['channel' => $channel, 'participants' => $participants],
            'cleanup:remove:'.Str::uuid(),
            priority: 70,
            maxAttempts: 1,
        );
        $remove = $this->waitForCommand($remove);

        if ($remove === null || $remove->status !== TelegramOwnerCommandStatus::Completed) {
            return self::FAILURE;
        }

        $removed = (int) ($remove->result['removed'] ?? 0);
        $failed = (int) ($remove->result['failed'] ?? 0);
        $this->info("Итог: удалено и разблокировано {$removed}, ошибок {$failed}.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function waitForCommand(TelegramOwnerCommand $command): ?TelegramOwnerCommand
    {
        $timeout = max(1, (int) $this->option('timeout'));
        $deadline = microtime(true) + $timeout;
        $this->line("Ожидаю Madeline owner command #{$command->id}...");

        do {
            usleep(250_000);
            $command->refresh();

            if (in_array($command->status, [
                TelegramOwnerCommandStatus::Completed,
                TelegramOwnerCommandStatus::Failed,
            ], true)) {
                if ($command->status === TelegramOwnerCommandStatus::Failed) {
                    $this->error($command->last_error ?: 'Madeline owner command завершилась с ошибкой.');
                }

                return $command;
            }
        } while (microtime(true) < $deadline);

        $this->error("Истёк таймаут ожидания owner-команды #{$command->id}.");

        return null;
    }

    private function resolveTelegramAccount(): ?TelegramAccount
    {
        $eligibleAccounts = TelegramAccount::query()
            ->where('is_active', true)
            ->whereIn('status', [
                TelegramAccountStatus::Authorized,
                TelegramAccountStatus::Connected,
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

        $this->error($accounts->isEmpty()
            ? 'Нет активных авторизованных Telegram-аккаунтов.'
            : 'Найдено несколько аккаунтов. Укажите нужный через --account=');

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

    /** @param list<array<string, mixed>> $participants */
    private function showPreview(array $participants): void
    {
        $preview = array_slice($participants, 0, 20);
        $this->table(
            ['#', 'Telegram user ID'],
            array_map(
                static fn (array $participant, int $index): array => [
                    $index + 1,
                    (int) ($participant['id'] ?? 0),
                ],
                $preview,
                array_keys($preview),
            ),
        );
    }
}
