<?php

namespace Tests\Unit;

use Amp\Http\Client\HttpClient;
use App\Models\TelegramAccount;
use App\Models\TelegramOwnerCommand;
use App\Services\TelegramOwnerCommandDispatcher;
use App\Telegram\ChannelSourceEventHandler;
use App\TelegramOwnerCommandStatus;
use App\TelegramOwnerCommandType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class MadelineClientPoolTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reuses_one_pending_owner_command_per_account_and_deduplication_key(): void
    {
        $account = TelegramAccount::factory()->create();
        $dispatcher = app(TelegramOwnerCommandDispatcher::class);

        $first = $dispatcher->dispatch(
            $account,
            TelegramOwnerCommandType::DownloadMedia,
            ['media_asset_id' => 10],
            'media:10:full',
        );
        $second = $dispatcher->dispatch(
            $account,
            TelegramOwnerCommandType::DownloadMedia,
            ['media_asset_id' => 10],
            'media:10:full',
        );

        $this->assertTrue($first->is($second));
        $this->assertDatabaseCount('telegram_owner_commands', 1);
    }

    public function test_a_finished_owner_command_is_reset_instead_of_creating_a_duplicate(): void
    {
        $account = TelegramAccount::factory()->create();
        $command = TelegramOwnerCommand::factory()->for($account)->create([
            'type' => TelegramOwnerCommandType::DownloadMedia,
            'status' => TelegramOwnerCommandStatus::Failed,
            'deduplication_key' => 'media:10:full',
            'attempts' => 1,
            'last_error' => 'Telegram failed.',
        ]);

        $requested = app(TelegramOwnerCommandDispatcher::class)->dispatch(
            $account,
            TelegramOwnerCommandType::DownloadMedia,
            ['media_asset_id' => 10],
            'media:10:full',
            maxAttempts: 1,
        );

        $this->assertTrue($command->is($requested));
        $this->assertSame(TelegramOwnerCommandStatus::Pending, $requested->status);
        $this->assertSame(0, $requested->attempts);
        $this->assertNull($requested->last_error);
        $this->assertDatabaseCount('telegram_owner_commands', 1);
    }

    public function test_telegram_bridge_reuses_a_dedicated_system_dns_http_client(): void
    {
        $bridgeHttpClient = new ReflectionMethod(ChannelSourceEventHandler::class, 'bridgeHttpClient');

        $first = $bridgeHttpClient->invoke(null);
        $second = $bridgeHttpClient->invoke(null);

        $this->assertInstanceOf(HttpClient::class, $first);
        $this->assertSame($first, $second);
    }
}
