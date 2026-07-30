<?php

namespace Tests\Feature;

use App\Models\TelegramAccount;
use App\Models\TelegramOwnerCommand;
use App\Services\MadelineOwnerLease;
use App\TelegramOwnerCommandStatus;
use App\TelegramOwnerCommandType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelegramOwnerHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_requires_a_fresh_owner_lease_for_every_active_account(): void
    {
        $account = TelegramAccount::factory()->create();
        $ownerLease = app(MadelineOwnerLease::class);

        $this->artisan('telegram:health')->assertFailed();

        $ownerLease->heartbeat($account->uuid);

        $this->artisan('telegram:health')
            ->expectsOutputToContain('MadelineProto owner готов')
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
}
