<?php

namespace Tests\Feature;

use App\Models\TelegramAccount;
use App\Models\TelegramOwnerCommand;
use App\Services\MadelineOwnerLease;
use App\TelegramOwnerCommandStatus;
use App\TelegramOwnerCommandType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramOwnerHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_requires_a_fresh_owner_lease_for_every_active_account(): void
    {
        $account = TelegramAccount::factory()->create();
        $ownerLease = app(MadelineOwnerLease::class);
        Http::fake([
            '*/system/getSessionList' => Http::response([
                'success' => true,
                'response' => [
                    'sessions' => [
                        $account->uuid => [
                            'session' => $account->uuid,
                            'file' => 'sessions/'.$account->uuid.'.madeline',
                            'status' => 'LOGGED_IN',
                        ],
                    ],
                ],
                'errors' => [],
            ]),
        ]);

        $this->artisan('telegram:health')->assertFailed();

        $ownerLease->heartbeat($account->uuid);

        $this->artisan('telegram:health')
            ->expectsOutputToContain('TelegramApiServer и owner worker готовы')
            ->assertSuccessful();

        $ownerLease->release($account->uuid);

        $this->artisan('telegram:health')->assertFailed();
    }

    public function test_owner_status_reports_pending_and_running_commands(): void
    {
        $account = TelegramAccount::factory()->create();
        TelegramOwnerCommand::factory()->for($account)->create([
            'type' => TelegramOwnerCommandType::DownloadMedia,
            'status' => TelegramOwnerCommandStatus::Pending,
        ]);
        TelegramOwnerCommand::factory()->for($account)->create([
            'type' => TelegramOwnerCommandType::SyncSourceHistory,
            'status' => TelegramOwnerCommandStatus::Running,
        ]);

        $this->artisan('telegram:owner:status')
            ->expectsTable(
                ['Аккаунт', 'Тип', 'Статус', 'Количество'],
                [
                    [$account->id, 'download_media', 'pending', 1],
                    [$account->id, 'sync_source_history', 'running', 1],
                ],
            )
            ->assertSuccessful();
    }

    public function test_failed_owner_command_can_be_retried_by_id(): void
    {
        $account = TelegramAccount::factory()->create();
        $target = TelegramOwnerCommand::factory()->for($account)->create([
            'status' => TelegramOwnerCommandStatus::Failed,
            'attempts' => 3,
        ]);
        $other = TelegramOwnerCommand::factory()->for($account)->create([
            'status' => TelegramOwnerCommandStatus::Failed,
            'attempts' => 3,
        ]);

        $this->artisan('telegram:owner:retry-failed', ['--id' => $target->id])
            ->expectsOutput('Повторно поставлено owner-команд: 1.')
            ->assertSuccessful();

        $this->assertSame(TelegramOwnerCommandStatus::Pending, $target->fresh()->status);
        $this->assertSame(0, $target->fresh()->attempts);
        $this->assertSame(TelegramOwnerCommandStatus::Failed, $other->fresh()->status);
        $this->assertSame(3, $other->fresh()->attempts);
    }
}
