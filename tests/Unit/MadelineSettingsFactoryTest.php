<?php

namespace Tests\Unit;

use App\Models\TelegramAccount;
use App\Services\MadelineSettingsFactory;
use danog\MadelineProto\Logger;
use danog\MadelineProto\Settings\Database\Postgres;
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
}
