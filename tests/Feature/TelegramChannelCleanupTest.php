<?php

namespace Tests\Feature;

use App\Contracts\MadelineClient;
use App\Models\TelegramAccount;
use App\Services\MadelineClientFactory;
use App\Services\MadelineClientPool;
use App\TelegramAccountStatus;
use danog\MadelineProto\RPCErrorException;
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
        $this->bindClient($client, $account);

        $this->artisan('telegram:channel:clean-deleted', [
            'channel' => 'https://t.me/pokatrend/123',
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('Найдено удалённых аккаунтов: 2')
            ->expectsOutputToContain('Режим dry-run')
            ->assertSuccessful();
    }

    public function test_command_stops_when_confirmation_is_rejected(): void
    {
        $account = TelegramAccount::factory()->create();
        $client = Mockery::mock(MadelineClient::class);
        $client->shouldReceive('canBanChannelParticipants')->once()->andReturnTrue();
        $client->shouldReceive('getChannelParticipants')
            ->once()
            ->andReturn($this->participantPage([
                ['id' => 101, 'deleted' => true],
            ], 1));
        $client->shouldNotReceive('banChannelParticipant');
        $client->shouldNotReceive('unbanChannelParticipant');
        $this->bindClient($client, $account);

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
        $client = Mockery::mock(MadelineClient::class);
        $client->shouldReceive('canBanChannelParticipants')->once()->andReturnTrue();
        $client->shouldReceive('getChannelParticipants')
            ->once()
            ->andReturn($this->participantPage([
                ['id' => 101, 'deleted' => true],
                ['id' => 102, 'deleted' => true],
            ], 2));
        $client->shouldReceive('banChannelParticipant')
            ->once()
            ->with('@pokatrend', 101)
            ->ordered();
        $client->shouldReceive('unbanChannelParticipant')
            ->once()
            ->with('@pokatrend', 101)
            ->ordered();
        $client->shouldReceive('banChannelParticipant')
            ->once()
            ->with('@pokatrend', 102)
            ->ordered();
        $client->shouldReceive('unbanChannelParticipant')
            ->once()
            ->with('@pokatrend', 102)
            ->ordered();
        $this->bindClient($client, $selectedAccount);

        $this->artisan('telegram:channel:clean-deleted', [
            'channel' => 'pokatrend',
            '--account' => '@ADMIN_TWO',
            '--force' => true,
        ])
            ->expectsOutputToContain(
                'Итог: найдено 2, удалено и разблокировано 2, осталось в бан-листе 0, ошибок 0.',
            )
            ->assertSuccessful();
    }

    public function test_command_reports_a_member_left_banned_when_unban_fails(): void
    {
        $account = TelegramAccount::factory()->create();
        $client = Mockery::mock(MadelineClient::class);
        $client->shouldReceive('canBanChannelParticipants')->once()->andReturnTrue();
        $client->shouldReceive('getChannelParticipants')
            ->once()
            ->andReturn($this->participantPage([
                ['id' => 101, 'deleted' => true],
            ], 1));
        $client->shouldReceive('banChannelParticipant')->once();
        $client->shouldReceive('unbanChannelParticipant')
            ->once()
            ->andThrow(new TelegramCleanupRpcException('INPUT_USER_DEACTIVATED'));
        $this->bindClient($client, $account);

        $this->artisan('telegram:channel:clean-deleted', [
            'channel' => '@pokatrend',
            '--force' => true,
        ])
            ->expectsOutputToContain('осталось в бан-листе 1')
            ->assertFailed();
    }

    public function test_flood_wait_stops_the_cleanup_before_remaining_members(): void
    {
        $account = TelegramAccount::factory()->create();
        $client = Mockery::mock(MadelineClient::class);
        $client->shouldReceive('canBanChannelParticipants')->once()->andReturnTrue();
        $client->shouldReceive('getChannelParticipants')
            ->once()
            ->andReturn($this->participantPage([
                ['id' => 101, 'deleted' => true],
                ['id' => 102, 'deleted' => true],
            ], 2));
        $client->shouldReceive('banChannelParticipant')
            ->once()
            ->with('@pokatrend', 101)
            ->andThrow(new TelegramCleanupRpcException('FLOOD_WAIT_120'));
        $client->shouldNotReceive('unbanChannelParticipant');
        $this->bindClient($client, $account);

        $this->artisan('telegram:channel:clean-deleted', [
            'channel' => '@pokatrend',
            '--force' => true,
        ])
            ->expectsOutputToContain('Очистка остановлена из-за критической ошибки Telegram')
            ->assertFailed();
    }

    public function test_command_fails_before_scanning_without_ban_permission(): void
    {
        $account = TelegramAccount::factory()->create();
        $client = Mockery::mock(MadelineClient::class);
        $client->shouldReceive('canBanChannelParticipants')->once()->andReturnFalse();
        $client->shouldNotReceive('getChannelParticipants');
        $this->bindClient($client, $account);

        $this->artisan('telegram:channel:clean-deleted', ['channel' => '@pokatrend'])
            ->expectsOutputToContain('не имеет права блокировать участников')
            ->assertFailed();
    }

    public function test_command_succeeds_without_confirmation_when_no_deleted_accounts_exist(): void
    {
        $account = TelegramAccount::factory()->create();
        $client = Mockery::mock(MadelineClient::class);
        $client->shouldReceive('canBanChannelParticipants')->once()->andReturnTrue();
        $client->shouldReceive('getChannelParticipants')
            ->once()
            ->andReturn($this->participantPage([], 0));
        $client->shouldNotReceive('banChannelParticipant');
        $client->shouldNotReceive('unbanChannelParticipant');
        $this->bindClient($client, $account);

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

    private function bindClient(
        MadelineClient $client,
        TelegramAccount $expectedAccount,
    ): void {
        $factory = Mockery::mock(MadelineClientFactory::class);
        $factory->shouldReceive('make')
            ->once()
            ->with(Mockery::on(
                fn (TelegramAccount $account): bool => $account->is($expectedAccount),
            ))
            ->andReturn($client);
        $this->app->instance(
            MadelineClientPool::class,
            new MadelineClientPool($factory),
        );
    }
}

class TelegramCleanupRpcException extends RPCErrorException
{
    public function __construct(string $rpc)
    {
        parent::__construct($rpc, $rpc, 400, 'channels.editBanned');
    }
}
