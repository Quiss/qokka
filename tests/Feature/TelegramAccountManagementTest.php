<?php

namespace Tests\Feature;

use App\Actions\AssignTelegramCollector;
use App\Actions\ReconcileTelegramCollectors;
use App\Models\SourceChannel;
use App\Models\TelegramAccount;
use App\Services\TelegramApiServer;
use App\TelegramAccountStatus;
use App\TelegramSourceAccessStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class TelegramAccountManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_telegram_api_server_does_not_require_the_legacy_bridge_secret(): void
    {
        config(['services.telegram.bridge_secret' => null]);
        Http::fake([
            '*/system/getSessionList' => Http::response([
                'success' => true,
                'response' => ['sessions' => []],
                'errors' => [],
            ]),
        ]);

        $this->assertSame([], app(TelegramApiServer::class)->sessions());
    }

    public function test_source_reference_is_normalized(): void
    {
        $source = SourceChannel::factory()->create([
            'username' => 'https://t.me/example_channel/123',
            'title' => null,
        ]);

        $this->assertSame('example_channel', $source->username);
        $this->assertSame('@example_channel', $source->title);
    }

    public function test_least_loaded_available_account_is_selected(): void
    {
        $first = TelegramAccount::factory()->create([
            'status' => TelegramAccountStatus::Connected,
            'last_seen_at' => now(),
        ]);
        $second = TelegramAccount::factory()->create([
            'status' => TelegramAccountStatus::Connected,
            'last_seen_at' => now(),
        ]);
        SourceChannel::factory()->create(['collector_telegram_account_id' => $first->id]);
        $source = SourceChannel::factory()->create([
            'username' => null,
            'collector_telegram_account_id' => null,
        ]);
        $source->telegramAccounts()->attach([
            $first->id => ['access_status' => TelegramSourceAccessStatus::Available],
            $second->id => ['access_status' => TelegramSourceAccessStatus::Available],
        ]);

        $selected = app(AssignTelegramCollector::class)->handle($source);

        $this->assertTrue($selected?->is($second));
        $this->assertTrue($source->fresh()->collectorTelegramAccount?->is($second));
    }

    public function test_stale_collector_is_replaced_by_a_healthy_account(): void
    {
        $stale = TelegramAccount::factory()->create([
            'status' => TelegramAccountStatus::Connected,
            'last_seen_at' => now()->subMinutes(4),
        ]);
        $healthy = TelegramAccount::factory()->create([
            'status' => TelegramAccountStatus::Connected,
            'last_seen_at' => now(),
        ]);
        $source = SourceChannel::factory()->create([
            'username' => null,
            'collector_telegram_account_id' => $stale->id,
        ]);
        $source->telegramAccounts()->attach([
            $stale->id => ['access_status' => TelegramSourceAccessStatus::Available],
            $healthy->id => ['access_status' => TelegramSourceAccessStatus::Available],
        ]);

        $count = app(ReconcileTelegramCollectors::class)->handle();

        $this->assertSame(1, $count);
        $this->assertTrue($source->fresh()->collectorTelegramAccount?->is($healthy));
    }

    public function test_signed_subscriptions_request_returns_only_assigned_sources(): void
    {
        config(['services.telegram.bridge_secret' => 'bridge-secret']);
        $account = TelegramAccount::factory()->create();
        $assigned = SourceChannel::factory()->create([
            'collector_telegram_account_id' => $account->id,
            'telegram_peer_id' => -100123,
        ]);
        SourceChannel::factory()->create(['telegram_peer_id' => -100999]);
        $body = json_encode(['telegram_account_uuid' => $account->uuid], JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->timestamp;
        $nonce = (string) Str::uuid();
        $signature = hash_hmac('sha256', $timestamp.'.'.$nonce.'.'.$body, 'bridge-secret');

        $response = $this->call('POST', '/api/internal/telegram/subscriptions', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_TELEGRAM_TIMESTAMP' => $timestamp,
            'HTTP_X_TELEGRAM_NONCE' => $nonce,
            'HTTP_X_TELEGRAM_SIGNATURE' => $signature,
        ], $body);

        $response->assertOk()->assertExactJson(['peer_ids' => [$assigned->telegram_peer_id]]);
        $this->assertSame(TelegramAccountStatus::Connected, $account->fresh()->status);
    }

    public function test_identical_subscription_requests_in_the_same_second_use_independent_nonces(): void
    {
        config(['services.telegram.bridge_secret' => 'bridge-secret']);
        $account = TelegramAccount::factory()->create();
        $body = json_encode(['telegram_account_uuid' => $account->uuid], JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->timestamp;

        foreach ([(string) Str::uuid(), (string) Str::uuid()] as $nonce) {
            $signature = hash_hmac('sha256', $timestamp.'.'.$nonce.'.'.$body, 'bridge-secret');

            $this->call('POST', '/api/internal/telegram/subscriptions', [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_TELEGRAM_TIMESTAMP' => $timestamp,
                'HTTP_X_TELEGRAM_NONCE' => $nonce,
                'HTTP_X_TELEGRAM_SIGNATURE' => $signature,
            ], $body)->assertOk();
        }
    }

    public function test_exact_bridge_request_replay_is_rejected(): void
    {
        config(['services.telegram.bridge_secret' => 'bridge-secret']);
        $account = TelegramAccount::factory()->create();
        $body = json_encode(['telegram_account_uuid' => $account->uuid], JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->timestamp;
        $nonce = (string) Str::uuid();
        $signature = hash_hmac('sha256', $timestamp.'.'.$nonce.'.'.$body, 'bridge-secret');
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_TELEGRAM_TIMESTAMP' => $timestamp,
            'HTTP_X_TELEGRAM_NONCE' => $nonce,
            'HTTP_X_TELEGRAM_SIGNATURE' => $signature,
        ];

        $this->call('POST', '/api/internal/telegram/subscriptions', [], [], [], $server, $body)
            ->assertOk();
        $this->call('POST', '/api/internal/telegram/subscriptions', [], [], [], $server, $body)
            ->assertConflict();
    }
}
