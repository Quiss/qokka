<?php

namespace Tests\Feature;

use Amp\Postgres\PgSqlConnection;
use Amp\Postgres\PostgresConfig;
use Tests\TestCase;

use function Amp\delay;

class AmpPostgresPreparedStatementCleanupTest extends TestCase
{
    public function test_a_statement_can_be_reprepared_after_postgres_discards_it(): void
    {
        $database = config('database.connections.pgsql');
        $this->assertIsArray($database);

        $connection = PgSqlConnection::connect(new PostgresConfig(
            host: (string) $database['host'],
            port: (int) $database['port'],
            user: (string) $database['username'],
            password: (string) $database['password'],
            database: (string) $database['database'],
        ));
        $query = 'SELECT CAST(:value AS TEXT) AS value';

        try {
            $statement = $connection->prepare($query);
            $connection->query('DISCARD ALL');
            $statement->close();
            delay(0.01);

            $repreparedStatement = $connection->prepare($query);
            $row = $repreparedStatement->execute(['value' => 'available'])->fetchRow();

            $this->assertSame('available', $row['value'] ?? null);

            $repreparedStatement->close();
            delay(0.01);
        } finally {
            $connection->close();
        }
    }
}
