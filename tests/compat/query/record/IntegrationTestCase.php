<?php

namespace Utopia\Query\Tests\E2E;

use PHPUnit\Framework\TestCase;
use Tests\Compat\Query\Record\Offline;
use Utopia\Query\Builder\Statement;

/**
 * Replaces packages/query/tests/E2E/IntegrationTestCase.php while recording:
 * the integration tests build statements with the library and run them on
 * real databases. Recording keeps the building (the library's behaviour) and
 * answers every database call with nothing, so the statements are recorded
 * without the databases.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected mixed $mysql = null;

    protected mixed $mariadb = null;

    protected mixed $postgres = null;

    protected mixed $sqlite = null;

    protected mixed $clickhouse = null;

    protected mixed $mongoClient = null;

    protected function connectMysql(): Offline
    {
        return $this->mysql ??= new Offline();
    }

    protected function connectMariadb(): Offline
    {
        return $this->mariadb ??= new Offline();
    }

    protected function connectPostgres(): Offline
    {
        return $this->postgres ??= new Offline();
    }

    protected function connectClickhouse(): Offline
    {
        return $this->clickhouse ??= new Offline();
    }

    protected function connectMongoDB(): Offline
    {
        return $this->mongoClient ??= new Offline();
    }

    protected function connectSqlite(): Offline
    {
        return $this->sqlite ??= new Offline();
    }

    protected function sqliteStatement(string $sql): void
    {
    }

    /** @return list<array<string, mixed>> */
    protected function executeOnSqlite(Statement $result): array
    {
        return [];
    }

    /** @return list<array<string, mixed>> */
    protected function executeOnMongoDB(Statement $result): array
    {
        return [];
    }

    protected function trackMongoCollection(string $collection): void
    {
    }

    /** @return list<array<string, mixed>> */
    protected function executeOnMysql(Statement $result): array
    {
        return [];
    }

    /** @return list<array<string, mixed>> */
    protected function executeOnMariadb(Statement $result): array
    {
        return [];
    }

    /** @return list<array<string, mixed>> */
    protected function executeOnPostgres(Statement $result): array
    {
        return [];
    }

    /** @return list<array<string, mixed>> */
    protected function executeOnClickhouse(Statement $result): array
    {
        return [];
    }

    protected function mysqlStatement(string $sql): void
    {
    }

    protected function mariadbStatement(string $sql): void
    {
    }

    protected function postgresStatement(string $sql): void
    {
    }

    protected function clickhouseStatement(string $sql): void
    {
    }

    protected function tableName(string $base): string
    {
        return $base;
    }

    protected function trackMysqlTable(string $table): void
    {
    }

    protected function trackMariadbTable(string $table): void
    {
    }

    protected function trackPostgresTable(string $table): void
    {
    }

    protected function trackClickhouseTable(string $table): void
    {
    }
}
