<?php

namespace App\Services;

use App\Models\TelegramAccount;
use danog\MadelineProto\Settings;
use danog\MadelineProto\Settings\Database\Postgres;
use Illuminate\Support\Facades\File;
use RuntimeException;

class MadelineSettingsFactory
{
    public function make(TelegramAccount $account): Settings
    {
        $connectionName = (string) config('database.default');
        $connection = config('database.connections.'.$connectionName);

        if (! is_array($connection) || ($connection['driver'] ?? null) !== 'pgsql') {
            throw new RuntimeException('MadelineProto database sessions require the PostgreSQL connection.');
        }

        $apiId = config('services.telegram.api_id');
        $apiHash = config('services.telegram.api_hash');

        if (blank($apiId) || blank($apiHash)) {
            throw new RuntimeException('TELEGRAM_API_ID and TELEGRAM_API_HASH must be configured.');
        }

        $database = (new Postgres)
            ->setUri('tcp://'.($connection['host'] ?? '127.0.0.1').':'.($connection['port'] ?? 5432))
            ->setDatabase((string) ($connection['database'] ?? 'laravel'))
            ->setUsername((string) ($connection['username'] ?? 'root'))
            ->setPassword((string) ($connection['password'] ?? ''))
            ->setMaxConnections(max(1, (int) config('services.telegram.database_max_connections')))
            ->setIdleTimeout(max(1, (int) config('services.telegram.database_idle_timeout')))
            ->setEphemeralFilesystemPrefix('mp_'.str_replace('-', '_', $account->uuid).'_');

        $settings = (new Settings)->setDb($database);
        $settings->getAppInfo()
            ->setApiId((int) $apiId)
            ->setApiHash((string) $apiHash);

        return $settings;
    }

    public function sessionPath(TelegramAccount $account): string
    {
        $directory = storage_path('app/telegram/sessions');
        File::ensureDirectoryExists($directory);

        return $directory.'/'.$account->uuid;
    }
}
