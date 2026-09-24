<?php

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Utopia\Query\Builder\Statement;

abstract class IntegrationTestCase extends TestCase
{
    protected ?PDO $mysql = null;

    protected ?PDO $mariadb = null;

    protected ?PDO $postgres = null;

    protected ?PDO $sqlite = null;

    protected ?ClickHouseClient $clickhouse = null;

    protected ?MongoDBClient $mongoClient = null;

    /** @var list<string> */
    private array $mysqlCleanup = [];

    /** @var list<string> */
    private array $mariadbCleanup = [];

    /** @var list<string> */
    private array $postgresCleanup = [];

    /** @var list<string> */
    private array $clickhouseCleanup = [];

    /** @var list<string> */
    private array $mongoCleanup = [];

    protected function connectMysql(): PDO
    {
        if ($this->mysql === null) {
            $this->mysql = new PDO(
                'mysql:host=127.0.0.1;port=13306;dbname=query_test',
                'root',
                'test',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
        }

        return $this->mysql;
    }

    protected function connectMariadb(): PDO
    {
        if ($this->mariadb === null) {
            $this->mariadb = new PDO(
                'mysql:host=127.0.0.1;port=13307;dbname=query_test',
                'root',
                'test',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
        }

        return $this->mariadb;
    }

    protected function connectPostgres(): PDO
    {
        if ($this->postgres === null) {
            $this->postgres = new PDO(
                'pgsql:host=127.0.0.1;port=15432;dbname=query_test',
                'postgres',
                'test',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
        }

        return $this->postgres;
    }

    protected function connectClickhouse(): ClickHouseClient
    {
        if ($this->clickhouse === null) {
            $this->clickhouse = new ClickHouseClient();
        }

        return $this->clickhouse;
    }

    protected function connectMongoDB(): MongoDBClient
    {
        if ($this->mongoClient === null) {
            $this->mongoClient = new MongoDBClient();
        }

        return $this->mongoClient;
    }

    protected function connectSqlite(): PDO
    {
        if ($this->sqlite === null) {
            $this->sqlite = new PDO('sqlite::memory:', null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $this->sqlite->exec('PRAGMA foreign_keys = ON');
        }

        return $this->sqlite;
    }

    protected function sqliteStatement(string $sql): void
    {
        $this->connectSqlite()->prepare($sql)->execute();
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function executeOnSqlite(Statement $result): array
    {
        $pdo = $this->connectSqlite();
        $stmt = $pdo->prepare($result->query);

        foreach ($result->bindings as $i => $value) {
            $type = match (true) {
                is_bool($value) => PDO::PARAM_BOOL,
                is_int($value) => PDO::PARAM_INT,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $stmt->bindValue($i + 1, $value, $type);
        }
        $stmt->execute();

        /** @var list<array<string, mixed>> */
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function executeOnMongoDB(Statement $result): array
    {
        $mongo = $this->connectMongoDB();

        return $mongo->execute($result->query, $result->bindings);
    }

    protected function trackMongoCollection(string $collection): void
    {
        $this->mongoCleanup[] = $collection;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function executeOnMysql(Statement $result): array
    {
        $pdo = $this->connectMysql();
        $stmt = $pdo->prepare($result->query);

        foreach ($result->bindings as $i => $value) {
            $type = match (true) {
                is_bool($value) => PDO::PARAM_BOOL,
                is_int($value) => PDO::PARAM_INT,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $stmt->bindValue($i + 1, $value, $type);
        }
        $stmt->execute();

        /** @var list<array<string, mixed>> */
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function executeOnMariadb(Statement $result): array
    {
        $pdo = $this->connectMariadb();
        $stmt = $pdo->prepare($result->query);

        foreach ($result->bindings as $i => $value) {
            $type = match (true) {
                is_bool($value) => PDO::PARAM_BOOL,
                is_int($value) => PDO::PARAM_INT,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $stmt->bindValue($i + 1, $value, $type);
        }
        $stmt->execute();

        /** @var list<array<string, mixed>> */
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function executeOnPostgres(Statement $result): array
    {
        $pdo = $this->connectPostgres();
        $stmt = $pdo->prepare($result->query);

        foreach ($result->bindings as $i => $value) {
            $type = match (true) {
                is_bool($value) => PDO::PARAM_BOOL,
                is_int($value) => PDO::PARAM_INT,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $stmt->bindValue($i + 1, $value, $type);
        }
        $stmt->execute();

        /** @var list<array<string, mixed>> */
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function executeOnClickhouse(Statement $result): array
    {
        $ch = $this->connectClickhouse();

        return $ch->execute($result->query, $result->bindings);
    }

    protected function mysqlStatement(string $sql): void
    {
        $this->connectMysql()->prepare($sql)->execute();
    }

    protected function mariadbStatement(string $sql): void
    {
        $this->connectMariadb()->prepare($sql)->execute();
    }

    protected function postgresStatement(string $sql): void
    {
        $this->connectPostgres()->prepare($sql)->execute();
    }

    protected function clickhouseStatement(string $sql): void
    {
        $this->connectClickhouse()->statement($sql);
    }

    /**
     * Returns a table/collection name suffixed with the paratest worker token,
     * when present. This defuses the race where parallel workers share the same
     * MySQL/MariaDB/PostgreSQL/ClickHouse/MongoDB containers and would otherwise
     * clobber each other's `users`/`orders`/etc tables in setUp.
     *
     * Today `composer test:integration` runs phpunit (not paratest), so
     * `TEST_TOKEN` is unset and this is a no-op that returns `$base` unchanged.
     * If integration tests are ever parallelised, every test that creates or
     * references a shared-container table should route its physical table name
     * through this helper to keep workers isolated.
     *
     * Usage:
     *
     *     $users = $this->tableName('users');
     *     $this->mysqlStatement("CREATE TABLE `{$users}` (...)");
     *     $this->trackMysqlTable($users);
     *     ...->from($users)->...->build();
     */
    protected function tableName(string $base): string
    {
        $token = getenv('TEST_TOKEN');
        if ($token === false || $token === '') {
            return $base;
        }

        return $base . '_' . $token;
    }

    protected function trackMysqlTable(string $table): void
    {
        $this->mysqlCleanup[] = $table;
    }

    protected function trackMariadbTable(string $table): void
    {
        $this->mariadbCleanup[] = $table;
    }

    protected function trackPostgresTable(string $table): void
    {
        $this->postgresCleanup[] = $table;
    }

    protected function trackClickhouseTable(string $table): void
    {
        $this->clickhouseCleanup[] = $table;
    }

    protected function tearDown(): void
    {
        $this->mysql?->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($this->mysqlCleanup as $table) {
            $stmt = $this->mysql?->prepare("DROP TABLE IF EXISTS `{$table}`");
            if ($stmt !== null && $stmt !== false) {
                $stmt->execute();
            }
        }
        $this->mysql?->exec('SET FOREIGN_KEY_CHECKS = 1');

        $this->mariadb?->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($this->mariadbCleanup as $table) {
            $stmt = $this->mariadb?->prepare("DROP TABLE IF EXISTS `{$table}`");
            if ($stmt !== null && $stmt !== false) {
                $stmt->execute();
            }
        }
        $this->mariadb?->exec('SET FOREIGN_KEY_CHECKS = 1');

        foreach ($this->postgresCleanup as $table) {
            $stmt = $this->postgres?->prepare("DROP TABLE IF EXISTS \"{$table}\" CASCADE");
            if ($stmt !== null && $stmt !== false) {
                $stmt->execute();
            }
        }

        foreach ($this->clickhouseCleanup as $table) {
            $this->clickhouse?->statement("DROP TABLE IF EXISTS `{$table}`");
        }

        foreach ($this->mongoCleanup as $collection) {
            $this->mongoClient?->dropCollection($collection);
        }

        $this->mysqlCleanup = [];
        $this->mariadbCleanup = [];
        $this->postgresCleanup = [];
        $this->clickhouseCleanup = [];
        $this->mongoCleanup = [];
    }
}
