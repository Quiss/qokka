<?php

namespace Tests\Unit;

use App\Services\MadelineSessionDatabaseSettingsSynchronizer;
use danog\AsyncOrm\DbArrayBuilder;
use danog\AsyncOrm\KeyType;
use danog\AsyncOrm\Settings\PostgresSettings;
use danog\AsyncOrm\ValueType;
use danog\MadelineProto\SessionPaths;
use danog\MadelineProto\Settings;
use danog\MadelineProto\Settings\Database\Postgres;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use Throwable;

class MadelineSessionDatabaseSettingsSynchronizerTest extends TestCase
{
    private string $sessionDirectory;

    /** @var callable|null */
    private $errorHandler;

    /** @var callable|null */
    private $exceptionHandler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->errorHandler = $this->currentErrorHandler();
        $this->exceptionHandler = $this->currentExceptionHandler();
        ob_start();

        $this->sessionDirectory = sys_get_temp_dir().'/channelbot-madeline-session-'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        ob_end_clean();

        while ($this->currentErrorHandler() !== $this->errorHandler) {
            restore_error_handler();
        }

        while ($this->currentExceptionHandler() !== $this->exceptionHandler) {
            restore_exception_handler();
        }

        (new Filesystem)->deleteDirectory($this->sessionDirectory);

        parent::tearDown();
    }

    public function test_it_replaces_persisted_pool_settings_without_changing_the_session_table(): void
    {
        $sessionPaths = new SessionPaths($this->sessionDirectory);
        $sessionTable = 'mp_test__MTProto_session';
        $oldDatabase = $this->postgresSettings(maxConnections: 1, idleTimeout: 10);
        $sessionPaths->serialize(
            new DbArrayBuilder(
                $sessionTable,
                $oldDatabase->getOrmSettings(),
                KeyType::STRING,
                ValueType::SCALAR,
            ),
            $sessionPaths->getSessionPath(),
        );

        $synchronized = (new MadelineSessionDatabaseSettingsSynchronizer)->synchronize(
            $this->sessionDirectory,
            (new Settings)->setDb($this->postgresSettings(maxConnections: 100, idleTimeout: 60)),
        );

        $storedSession = $sessionPaths->unserialize();

        $this->assertTrue($synchronized);
        $this->assertInstanceOf(DbArrayBuilder::class, $storedSession);
        $this->assertSame($sessionTable, $storedSession->table);
        $this->assertInstanceOf(PostgresSettings::class, $storedSession->settings);
        $this->assertSame(100, $storedSession->settings->maxConnections);
        $this->assertSame(60, $storedSession->settings->idleTimeout);
    }

    public function test_it_does_not_rewrite_current_persisted_settings(): void
    {
        $sessionPaths = new SessionPaths($this->sessionDirectory);
        $database = $this->postgresSettings(maxConnections: 100, idleTimeout: 60);
        $sessionPaths->serialize(
            new DbArrayBuilder(
                'mp_test__MTProto_session',
                $database->getOrmSettings(),
                KeyType::STRING,
                ValueType::SCALAR,
            ),
            $sessionPaths->getSessionPath(),
        );
        $modifiedAt = filemtime($sessionPaths->getSessionPath());

        $synchronized = (new MadelineSessionDatabaseSettingsSynchronizer)->synchronize(
            $this->sessionDirectory,
            (new Settings)->setDb($database),
        );

        $this->assertFalse($synchronized);
        $this->assertSame($modifiedAt, filemtime($sessionPaths->getSessionPath()));
    }

    public function test_it_does_not_replace_a_non_database_session_file(): void
    {
        $sessionPaths = new SessionPaths($this->sessionDirectory);
        $sessionPaths->serialize('legacy-session', $sessionPaths->getSessionPath());

        $synchronized = (new MadelineSessionDatabaseSettingsSynchronizer)->synchronize(
            $this->sessionDirectory,
            (new Settings)->setDb($this->postgresSettings(maxConnections: 100, idleTimeout: 60)),
        );

        $this->assertFalse($synchronized);
        $this->assertSame('legacy-session', $sessionPaths->unserialize());
    }

    private function postgresSettings(int $maxConnections, int $idleTimeout): Postgres
    {
        return (new Postgres)
            ->setUri('tcp://pgsql:5432')
            ->setDatabase('channelbot')
            ->setUsername('channelbot')
            ->setPassword('secret')
            ->setMaxConnections($maxConnections)
            ->setIdleTimeout($idleTimeout)
            ->setEphemeralFilesystemPrefix('mp_test_');
    }

    private function currentErrorHandler(): ?callable
    {
        $currentHandler = set_error_handler(static fn (): bool => false);
        restore_error_handler();

        return $currentHandler;
    }

    private function currentExceptionHandler(): ?callable
    {
        $currentHandler = set_exception_handler(static fn (Throwable $exception): null => null);
        restore_exception_handler();

        return $currentHandler;
    }
}
