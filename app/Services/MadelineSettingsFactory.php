<?php

namespace App\Services;

use App\Models\TelegramAccount;
use danog\MadelineProto\Logger;
use danog\MadelineProto\Settings;
use danog\MadelineProto\Settings\Database\Postgres;
use danog\MadelineProto\Stream\MTProtoTransport\HttpsStream;
use danog\MadelineProto\Stream\Proxy\SocksProxy;
use Illuminate\Support\Facades\File;
use RuntimeException;

class MadelineSettingsFactory
{
    private const int DEFAULT_DATABASE_IDLE_TIMEOUT = 300;

    private const int DEFAULT_DATABASE_MAX_CONNECTIONS = 20;

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
            ->setMaxConnections($this->positiveIntegerConfig(
                'services.telegram.database_max_connections',
                self::DEFAULT_DATABASE_MAX_CONNECTIONS,
            ))
            ->setIdleTimeout($this->positiveIntegerConfig(
                'services.telegram.database_idle_timeout',
                self::DEFAULT_DATABASE_IDLE_TIMEOUT,
            ))
            ->setEphemeralFilesystemPrefix('mp_'.str_replace('-', '_', $account->uuid).'_');

        $settings = (new Settings)->setDb($database);
        $settings->getLogger()->setLevel(Logger::NOTICE);
        $settings->getAppInfo()
            ->setApiId((int) $apiId)
            ->setApiHash((string) $apiHash);
        $settings->getFiles()->setDownloadParallelChunks($this->positiveIntegerConfig(
            'services.telegram.download_parallel_chunks',
            4,
        ));
        $settings->getRpc()->setRpcDropTimeout($this->positiveIntegerConfig(
            'services.telegram.rpc_drop_timeout',
            60,
        ));
        $this->configureSocks5Proxy($settings);

        return $settings;
    }

    public function sessionPath(TelegramAccount $account): string
    {
        $directory = storage_path('app/telegram/sessions');
        File::ensureDirectoryExists($directory);

        return $directory.'/'.$account->uuid;
    }

    /**
     * @param  positive-int  $default
     * @return positive-int
     */
    private function positiveIntegerConfig(string $key, int $default): int
    {
        $value = (int) config($key, $default);

        return $value > 0 ? $value : $default;
    }

    private function configureSocks5Proxy(Settings $settings): void
    {
        $host = trim((string) config('services.telegram.socks5.host'));

        if ($host === '') {
            return;
        }

        $port = (int) config('services.telegram.socks5.port', 1080);

        if ($port < 1 || $port > 65535) {
            throw new RuntimeException('TELEGRAM_SOCKS5_PORT must be between 1 and 65535.');
        }

        $username = trim((string) config('services.telegram.socks5.username'));
        $password = (string) config('services.telegram.socks5.password');

        if (($username === '') !== ($password === '')) {
            throw new RuntimeException(
                'TELEGRAM_SOCKS5_USERNAME and TELEGRAM_SOCKS5_PASSWORD must be configured together.',
            );
        }

        $proxy = [
            'address' => $host,
            'port' => $port,
        ];

        if ($username !== '') {
            $proxy['username'] = $username;
            $proxy['password'] = $password;
        }

        $connection = $settings->getConnection()
            ->addProxy(SocksProxy::class, $proxy)
            ->setBindTo('0.0.0.0:0')
            ->setRetry(! (bool) config('services.telegram.socks5.proxy_only', true));

        if ((bool) config('services.telegram.socks5.https_transport', true)) {
            $connection->setProtocol(HttpsStream::class);
        }
    }
}
