<?php

namespace Tests\Unit;

use App\Models\TelegramAccount;
use App\Services\MadelineSettingsFactory;
use danog\MadelineProto\Settings\Database\Postgres;
use Tests\TestCase;

class MadelineSettingsFactoryTest extends TestCase
{
    public function test_it_defaults_each_telegram_session_to_one_database_connection(): void
    {
        config([
            'database.default' => 'pgsql',
            'services.telegram.api_id' => 12345,
            'services.telegram.api_hash' => 'test-api-hash',
        ]);
        $account = new TelegramAccount(['uuid' => 'c1b0acaa-9df6-44e3-b913-04df40501e32']);

        $database = app(MadelineSettingsFactory::class)->make($account)->getDb();

        $this->assertInstanceOf(Postgres::class, $database);
        $this->assertSame(1, $database->getMaxConnections());
    }

    public function test_it_limits_each_telegram_session_database_pool(): void
    {
        config([
            'database.default' => 'pgsql',
            'services.telegram.api_id' => 12345,
            'services.telegram.api_hash' => 'test-api-hash',
            'services.telegram.database_max_connections' => 3,
            'services.telegram.database_idle_timeout' => 15,
        ]);
        $account = new TelegramAccount(['uuid' => 'f79ad9ab-e802-41ac-b091-25c4e3ca0d09']);

        $database = app(MadelineSettingsFactory::class)->make($account)->getDb();

        $this->assertInstanceOf(Postgres::class, $database);
        $this->assertSame(3, $database->getMaxConnections());
        $this->assertSame(15, $database->getIdleTimeout());
    }

    public function test_it_clamps_invalid_pool_limits_to_positive_values(): void
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
        $this->assertSame(1, $database->getMaxConnections());
        $this->assertSame(1, $database->getIdleTimeout());
    }
}
