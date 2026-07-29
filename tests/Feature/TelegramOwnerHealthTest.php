<?php

namespace Tests\Feature;

use App\Models\TelegramAccount;
use App\Services\MadelineOwnerLease;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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

    public function test_media_drain_command_reports_a_busy_account_lock(): void
    {
        $account = TelegramAccount::factory()->create();
        $lock = Cache::store(
            (string) config('services.telegram.coordination_cache_store'),
        )->lock('telegram:media-download:'.$account->uuid, 30);
        $this->assertTrue($lock->get());

        $this->artisan('telegram:wait-for-media-downloads', ['--timeout' => 0])
            ->expectsOutputToContain($account->name)
            ->assertFailed();

        $lock->release();

        $this->artisan('telegram:wait-for-media-downloads', ['--timeout' => 0])
            ->assertSuccessful();
    }
}
