<?php

namespace Tests\Feature;

use App\Contracts\MadelineClient;
use App\Models\TelegramAccount;
use App\Models\TelegramOwnerCommand;
use App\Services\TelegramOwnerCommandDispatcher;
use App\Services\TelegramOwnerCommandExecutor;
use App\TelegramAccountStatus;
use App\TelegramOwnerCommandStatus;
use App\TelegramOwnerCommandType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class TelegramChannelCleanupTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_paginates_and_selects_only_real_deleted_accounts(): void
    {
        $account = TelegramAccount::factory()->create();
        $client = Mockery::mock(MadelineClient::class);
        $client->shouldReceive('canBanChannelParticipants')
            ->once()
            ->with('@pokatrend')
            ->andReturnTrue();
        $client->shouldReceive('getChannelParticipants')
            ->once()
            ->with('@pokatrend', 0, 200)
            ->andReturn($this->participantPage([
                ['id' => 101, 'deleted' => true],
                ['id' => 102, 'first_name' => 'Deleted Account'],
            ], 3));
        $client->shouldReceive('getChannelParticipants')
            ->once()
            ->with('@pokatrend', 2, 200)
            ->andReturn($this->participantPage([
                ['id' => 103, 'deleted' => true],
            ], 3));
        $client->shouldNotReceive('banChannelParticipant');
        $client->shouldNotReceive('unbanChannelParticipant');
        $command = new TelegramOwnerCommand([
            'telegram_account_id' => $account->id,
            'type' => TelegramOwnerCommandType::ScanDeletedParticipants,
            'payload' => ['channel' => '@pokatrend'],
        ]);

        $result = app(TelegramOwnerCommandExecutor::class)->execute($command, $client);

        $this->assertSame([101, 103], array_column($result['participants'], 'id'));
    }

    public function test_command_stops_when_confirmation_is_rejected(): void
    {
        $account = TelegramAccount::factory()->create();
        $this->bindCommands([
            $this->completedCommand(
                $account,
                TelegramOwnerCommandType::ScanDeletedParticipants,
                ['participants' => [['id' => 101, 'deleted' => true]]],
            ),
        ]);

        $this->artisan('telegram:channel:clean-deleted', ['channel' => '@pokatrend'])
            ->expectsConfirmation(
                'Удалить найденные деактивированные аккаунты (1) из @pokatrend и затем снять с них блокировку?',
                'no',
            )
            ->expectsOutputToContain('Очистка отменена')
            ->assertSuccessful();
    }

    public function test_force_removes_and_unbans_every_deleted_account_with_selected_admin(): void
    {
        TelegramAccount::factory()->create([
            'name' => 'first',
            'username' => 'first_admin',
        ]);
        $selectedAccount = TelegramAccount::factory()->create([
            'name' => 'second',
            'username' => 'admin_two',
        ]);
        $this->bindCommands([
            $this->completedCommand(
                $selectedAccount,
                TelegramOwnerCommandType::ScanDeletedParticipants,
                ['participants' => [
                    ['id' => 101, 'deleted' => true],
                    ['id' => 102, 'deleted' => true],
                ]],
            ),
            $this->completedCommand(
                $selectedAccount,
                TelegramOwnerCommandType::RemoveDeletedParticipants,
                ['removed' => 2, 'failed' => 0],
            ),
        ]);

        $this->artisan('telegram:channel:clean-deleted', [
            'channel' => 'pokatrend',
            '--account' => '@ADMIN_TWO',
            '--force' => true,
        ])
            ->expectsOutputToContain('Итог: удалено и разблокировано 2, ошибок 0.')
            ->assertSuccessful();
    }

    public function test_command_reports_a_member_left_banned_when_unban_fails(): void
    {
        $account = TelegramAccount::factory()->create();
        $this->bindCommands([
            $this->completedCommand(
                $account,
                TelegramOwnerCommandType::ScanDeletedParticipants,
                ['participants' => [['id' => 101, 'deleted' => true]]],
            ),
            $this->completedCommand(
                $account,
                TelegramOwnerCommandType::RemoveDeletedParticipants,
                ['removed' => 0, 'failed' => 1],
            ),
        ]);

        $this->artisan('telegram:channel:clean-deleted', [
            'channel' => '@pokatrend',
            '--force' => true,
        ])
            ->expectsOutputToContain('ошибок 1')
            ->assertFailed();
    }

    public function test_flood_wait_stops_the_cleanup_before_remaining_members(): void
    {
        $account = TelegramAccount::factory()->create();
        $this->bindCommands([
            $this->completedCommand(
                $account,
                TelegramOwnerCommandType::ScanDeletedParticipants,
                ['participants' => [
                    ['id' => 101, 'deleted' => true],
                    ['id' => 102, 'deleted' => true],
                ]],
            ),
            $this->failedCommand(
                $account,
                TelegramOwnerCommandType::RemoveDeletedParticipants,
                'FLOOD_WAIT_120',
            ),
        ]);

        $this->artisan('telegram:channel:clean-deleted', [
            'channel' => '@pokatrend',
            '--force' => true,
        ])
            ->expectsOutputToContain('FLOOD_WAIT_120')
            ->assertFailed();
    }

    public function test_command_fails_before_scanning_without_ban_permission(): void
    {
        $account = TelegramAccount::factory()->create();
        $this->bindCommands([
            $this->failedCommand(
                $account,
                TelegramOwnerCommandType::ScanDeletedParticipants,
                'Telegram-аккаунт не может удалять участников @pokatrend.',
            ),
        ]);

        $this->artisan('telegram:channel:clean-deleted', ['channel' => '@pokatrend'])
            ->expectsOutputToContain('не может удалять участников')
            ->assertFailed();
    }

    public function test_command_succeeds_without_confirmation_when_no_deleted_accounts_exist(): void
    {
        $account = TelegramAccount::factory()->create();
        $this->bindCommands([
            $this->completedCommand(
                $account,
                TelegramOwnerCommandType::ScanDeletedParticipants,
                ['participants' => []],
            ),
        ]);

        $this->artisan('telegram:channel:clean-deleted', ['channel' => '@pokatrend'])
            ->expectsOutputToContain('Найдено удалённых аккаунтов: 0')
            ->assertSuccessful();
    }

    public function test_command_fails_when_no_authorized_account_exists(): void
    {
        TelegramAccount::factory()->create([
            'status' => TelegramAccountStatus::Error,
        ]);

        $this->artisan('telegram:channel:clean-deleted', ['channel' => '@pokatrend'])
            ->expectsOutputToContain('Нет активных авторизованных Telegram-аккаунтов')
            ->assertFailed();
    }

    public function test_command_requires_account_selector_when_multiple_accounts_are_available(): void
    {
        TelegramAccount::factory()->count(2)->create([
            'status' => TelegramAccountStatus::Authorized,
        ]);

        $this->artisan('telegram:channel:clean-deleted', ['channel' => '@pokatrend'])
            ->expectsOutputToContain('Найдено несколько аккаунтов')
            ->assertFailed();
    }

    /**
     * @param  list<array<string, mixed>>  $users
     * @return array<string, mixed>
     */
    private function participantPage(array $users, int $count): array
    {
        return [
            '_' => 'channels.channelParticipants',
            'count' => $count,
            'participants' => array_map(
                fn (array $user): array => [
                    '_' => 'channelParticipant',
                    'user_id' => $user['id'],
                    'date' => now()->timestamp,
                ],
                $users,
            ),
            'users' => array_map(
                fn (array $user): array => ['_' => 'user', ...$user],
                $users,
            ),
            'chats' => [],
        ];
    }

    /** @param list<TelegramOwnerCommand> $commands */
    private function bindCommands(array $commands): void
    {
        $dispatcher = Mockery::mock(TelegramOwnerCommandDispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->times(count($commands))
            ->andReturn(...$commands);

        $this->app->instance(TelegramOwnerCommandDispatcher::class, $dispatcher);
    }

    /** @param array<string, mixed> $result */
    private function completedCommand(
        TelegramAccount $account,
        TelegramOwnerCommandType $type,
        array $result,
    ): TelegramOwnerCommand {
        return TelegramOwnerCommand::factory()->for($account)->create([
            'type' => $type,
            'status' => TelegramOwnerCommandStatus::Completed,
            'result' => $result,
            'finished_at' => now(),
        ]);
    }

    private function failedCommand(
        TelegramAccount $account,
        TelegramOwnerCommandType $type,
        string $error,
    ): TelegramOwnerCommand {
        return TelegramOwnerCommand::factory()->for($account)->create([
            'type' => $type,
            'status' => TelegramOwnerCommandStatus::Failed,
            'last_error' => $error,
            'finished_at' => now(),
        ]);
    }
}
