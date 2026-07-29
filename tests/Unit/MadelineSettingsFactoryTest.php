<?php

namespace Tests\Unit;

use App\Models\TelegramAccount;
use App\Services\MadelineSettingsFactory;
use danog\MadelineProto\Logger;
use danog\MadelineProto\Settings\Database\Postgres;
use danog\MadelineProto\Stream\Proxy\SocksProxy;
use RuntimeException;
use Tests\TestCase;

class MadelineSettingsFactoryTest extends TestCase
{
    public function test_it_uses_notice_logging_level(): void
    {
        config([
            'database.default' => 'pgsql',
            'services.telegram.api_id' => 12345,
            'services.telegram.api_hash' => 'test-api-hash',
        ]);
        $account = new TelegramAccount(['uuid' => '09ef5735-e830-4f1b-b3a4-5950d1536763']);

        $logger = app(MadelineSettingsFactory::class)->make($account)->getLogger();

        $this->assertSame(Logger::NOTICE, $logger->getLevel());
    }

    public function test_it_uses_madeline_safe_database_pool_defaults(): void
    {
        config([
            'database.default' => 'pgsql',
            'services.telegram.api_id' => 12345,
            'services.telegram.api_hash' => 'test-api-hash',
        ]);
        $account = new TelegramAccount(['uuid' => 'c1b0acaa-9df6-44e3-b913-04df40501e32']);

        $database = app(MadelineSettingsFactory::class)->make($account)->getDb();

        $this->assertInstanceOf(Postgres::class, $database);
        $this->assertSame(20, $database->getMaxConnections());
        $this->assertSame(300, $database->getIdleTimeout());
    }

    public function test_it_applies_application_pool_overrides(): void
    {
        config([
            'database.default' => 'pgsql',
            'services.telegram.api_id' => 12345,
            'services.telegram.api_hash' => 'test-api-hash',
            'services.telegram.database_max_connections' => 12,
            'services.telegram.database_idle_timeout' => 600,
        ]);
        $account = new TelegramAccount(['uuid' => 'f79ad9ab-e802-41ac-b091-25c4e3ca0d09']);

        $database = app(MadelineSettingsFactory::class)->make($account)->getDb();

        $this->assertInstanceOf(Postgres::class, $database);
        $this->assertSame(12, $database->getMaxConnections());
        $this->assertSame(600, $database->getIdleTimeout());
    }

    public function test_it_keeps_safe_defaults_when_pool_config_is_invalid(): void
    {
        config([
            'database.default' => 'pgsql',
            'services.telegram.api_id' => 12345,
            'services.telegram.api_hash' => 'test-api-hash',
            'services.telegram.database_max_connections' => 0,
            'services.telegram.database_idle_timeout' => 0,
        ]);
        $account = new TelegramAccount(['uuid' => '8ef9f222-e44e-443e-825f-d8ef69878668']);

        $database = app(MadelineSettingsFactory::class)->make($account)->getDb();

        $this->assertInstanceOf(Postgres::class, $database);
        $this->assertSame(20, $database->getMaxConnections());
        $this->assertSame(300, $database->getIdleTimeout());
    }

    public function test_it_configures_an_authenticated_socks5_proxy_without_direct_fallback(): void
    {
        config([
            'database.default' => 'pgsql',
            'services.telegram.api_id' => 12345,
            'services.telegram.api_hash' => 'test-api-hash',
            'services.telegram.socks5' => [
                'host' => 'tgprx.orangepanda.ru',
                'port' => 1080,
                'username' => 'channelbot',
                'password' => 'secret',
                'proxy_only' => true,
            ],
        ]);
        $account = new TelegramAccount(['uuid' => '299d4c77-247b-4e37-867c-19195e0ab435']);

        $connection = app(MadelineSettingsFactory::class)->make($account)->getConnection();

        $this->assertSame([[
            'address' => 'tgprx.orangepanda.ru',
            'port' => 1080,
            'username' => 'channelbot',
            'password' => 'secret',
        ]], $connection->getProxies()[SocksProxy::class]);
        $this->assertSame('0.0.0.0:0', $connection->getBindTo());
        $this->assertFalse($connection->getRetry());
    }

    public function test_it_keeps_direct_connections_when_socks5_proxy_is_not_configured(): void
    {
        config([
            'database.default' => 'pgsql',
            'services.telegram.api_id' => 12345,
            'services.telegram.api_hash' => 'test-api-hash',
            'services.telegram.socks5.host' => null,
        ]);
        $account = new TelegramAccount(['uuid' => '4d912351-adb2-44bf-811b-0497da734d68']);

        $connection = app(MadelineSettingsFactory::class)->make($account)->getConnection();

        $this->assertSame([], $connection->getProxies());
        $this->assertNull($connection->getBindTo());
        $this->assertTrue($connection->getRetry());
    }

    public function test_it_rejects_an_invalid_socks5_port(): void
    {
        config([
            'database.default' => 'pgsql',
            'services.telegram.api_id' => 12345,
            'services.telegram.api_hash' => 'test-api-hash',
            'services.telegram.socks5.host' => 'tgprx.orangepanda.ru',
            'services.telegram.socks5.port' => 70000,
        ]);
        $account = new TelegramAccount(['uuid' => '36f29990-3538-46eb-8726-bc6ef633e1eb']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('TELEGRAM_SOCKS5_PORT must be between 1 and 65535.');

        app(MadelineSettingsFactory::class)->make($account);
    }

    public function test_it_requires_both_socks5_credentials(): void
    {
        config([
            'database.default' => 'pgsql',
            'services.telegram.api_id' => 12345,
            'services.telegram.api_hash' => 'test-api-hash',
            'services.telegram.socks5' => [
                'host' => 'tgprx.orangepanda.ru',
                'port' => 1080,
                'username' => 'channelbot',
                'password' => null,
                'proxy_only' => true,
            ],
        ]);
        $account = new TelegramAccount(['uuid' => '12be8118-d5a7-48f3-ae24-69216b38ce63']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'TELEGRAM_SOCKS5_USERNAME and TELEGRAM_SOCKS5_PASSWORD must be configured together.',
        );

        app(MadelineSettingsFactory::class)->make($account);
    }
}
