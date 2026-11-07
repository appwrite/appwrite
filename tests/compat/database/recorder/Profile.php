<?php

namespace Tests\Compat\Database\Recorder;

/**
 * A recording profile: the suite it runs and how its cases open their
 * database.
 */
final class Profile
{
    /**
     * @param string $parent the suite's test class, whose tests (and scope traits') are recorded
     * @param string $suite its file under tests/e2e/Adapter/ (named in the cases' `source`)
     * @param string $prefix of the case files: `Scope-<prefix><Trait>.json`
     * @param string $schema the recording's schema (database name)
     * @param array<string, mixed>|\stdClass $open the cases' `db.open` arguments
     * @param list<string> $state the snapshot kinds of its cases
     * @param list<array<string, mixed>> $opening steps every case runs after its `db.open`
     * @param string|null $files the scope case files' prefix, when not `Scope-<prefix>`
     */
    public function __construct(
        public string $name,
        public string $parent,
        public string $suite,
        public string $prefix,
        public string $schema,
        public array|\stdClass $open,
        public array $state,
        public array $opening = [],
        public ?string $files = null,
    ) {
    }

    /** The scope cases' file prefix: `Scope-<prefix>` unless `files` names another. */
    public function scope(): string
    {
        return $this->files ?? 'Scope-' . $this->prefix;
    }

    /** The adapter its `db.open` picks (`engine` or `driver`): postgres, sqlite, memory, mariadb, mysql or mongo. */
    public function engine(): string
    {
        $open = (array) $this->open;

        return (string) ($open['engine'] ?? $open['driver'] ?? 'postgres');
    }

    /** Whether its tables are shared (tenant 999). */
    public function shared(): bool
    {
        return (bool) (((array) $this->open)['shared_tables'] ?? false);
    }

    public static function get(string $name): ?self
    {
        return match ($name) {
            'postgres' => new self($name, 'Tests\\E2E\\Adapter\\PostgresTest', 'PostgresTest.php', '', 'cxrecorderp', new \stdClass(), ['postgres@database-postgres']),
            'shared' => new self($name, 'Tests\\E2E\\Adapter\\SharedTables\\PostgresTest', 'SharedTables/PostgresTest.php', 'SharedTables-', 'cxrecorders', ['namespace' => '', 'shared_tables' => true, 'tenant' => 999], ['postgres@database-postgres']),
            // PoolTest: the suite over a Pool of 10 connections and a Redis
            // cache (Json entries), on PostgreSQL rather than MySQL; its own
            // tests are recorded too (PoolTest.json).
            'pool' => new self($name, 'Tests\\E2E\\Adapter\\PoolTest', 'PoolTest.php', 'Pool-', 'cxrecorderpl', ['pool' => ['size' => 10], 'cache' => 'redis'], ['postgres@database-postgres']),
            // RedisTest: the suite over the Redis adapter (no cache), in a
            // namespace of the case's own; SharedTables\RedisTest with shared
            // tables, tenant 999 and the empty namespace.
            'redis' => new self($name, 'Tests\\E2E\\Adapter\\RedisTest', 'RedisTest.php', 'Redis-', 'cxrecorderr', ['redis' => true, 'cache' => 'none', 'namespace' => '${ns}'], ['redis-json@database-redis-adapter']),
            'shared-redis' => new self($name, 'Tests\\E2E\\Adapter\\SharedTables\\RedisTest', 'SharedTables/RedisTest.php', 'SharedTables-Redis-', 'cxrecorderrs', ['redis' => true, 'cache' => 'none', 'namespace' => '', 'shared_tables' => true, 'tenant' => 999], ['redis-json@database-redis-adapter']),
            // MirrorTest: a Mirror of a PostgreSQL database (Redis cache) onto
            // a second PostgreSQL database (its own Redis cache), as the
            // suite mirrors MariaDB onto MariaDB; `src` and `dst` are the
            // handles of the two.
            'mirror' => new self(
                $name,
                'Tests\\E2E\\Adapter\\MirrorTest',
                'MirrorTest.php',
                'Mirror-',
                'cxrecorderm',
                ['mirror' => ['destination' => true], 'cache' => 'redis'],
                ['postgres@database-postgres', 'postgres@database-postgres-mirror'],
                [
                    ['op' => 'db.mirror', 'args' => ['part' => 'source'], 'bind' => 'src'],
                    ['op' => 'db.mirror', 'args' => ['part' => 'destination'], 'bind' => 'dst'],
                ],
            ),
            // SQLiteTest (a SQLite file, MySQL emulated as the suite sets it)
            // and SharedTables\SQLiteTest; MemoryTest on the in-memory adapter.
            'sqlite' => new self($name, 'Tests\\E2E\\Adapter\\SQLiteTest', 'SQLiteTest.php', 'SQLite-', 'cxrecorderq', ['engine' => 'sqlite', 'emulate_mysql' => true], ['sqlite']),
            'sqlite-shared' => new self($name, 'Tests\\E2E\\Adapter\\SharedTables\\SQLiteTest', 'SharedTables/SQLiteTest.php', 'SQLite-SharedTables-', 'cxrecordert', ['engine' => 'sqlite', 'emulate_mysql' => true, 'namespace' => '', 'shared_tables' => true, 'tenant' => 999], ['sqlite']),
            'memory' => new self($name, 'Tests\\E2E\\Adapter\\MemoryTest', 'MemoryTest.php', 'Memory-', 'cxrecorderx', ['engine' => 'memory'], []),
            // MariaDBTest and MySQLTest, and their SharedTables twins, on the
            // compat MariaDB/MySQL (a database per case): MariaDB-Scope-*.json,
            // MySQL-Scope-*.json.
            'mariadb' => new self($name, 'Tests\\E2E\\Adapter\\MariaDBTest', 'MariaDBTest.php', 'MariaDB-', 'cxrecorderpm', ['driver' => 'mariadb'], ['mysql@database-mariadb'], files: 'MariaDB-Scope-'),
            'shared-mariadb' => new self($name, 'Tests\\E2E\\Adapter\\SharedTables\\MariaDBTest', 'SharedTables/MariaDBTest.php', 'MariaDB-SharedTables-', 'cxrecordersm', ['driver' => 'mariadb', 'namespace' => '', 'shared_tables' => true, 'tenant' => 999], ['mysql@database-mariadb'], files: 'MariaDB-Scope-SharedTables-'),
            'mysql' => new self($name, 'Tests\\E2E\\Adapter\\MySQLTest', 'MySQLTest.php', 'MySQL-', 'cxrecorderpy', ['driver' => 'mysql'], ['mysql@database-mysql'], files: 'MySQL-Scope-'),
            'shared-mysql' => new self($name, 'Tests\\E2E\\Adapter\\SharedTables\\MySQLTest', 'SharedTables/MySQLTest.php', 'MySQL-SharedTables-', 'cxrecordersy', ['driver' => 'mysql', 'namespace' => '', 'shared_tables' => true, 'tenant' => 999], ['mysql@database-mysql'], files: 'MySQL-Scope-SharedTables-'),
            // MongoDBTest and its SharedTables and Schemaless twins on the
            // compat MongoDB (a database per case).
            'mongo' => new self($name, 'Tests\\E2E\\Adapter\\MongoDBTest', 'MongoDBTest.php', 'Mongo-', 'cxrecordermg', ['engine' => 'mongo'], ['mongo@database-mongo']),
            'mongo-shared' => new self($name, 'Tests\\E2E\\Adapter\\SharedTables\\MongoDBTest', 'SharedTables/MongoDBTest.php', 'Mongo-SharedTables-', 'cxrecordermgs', ['engine' => 'mongo', 'shared_tables' => true, 'tenant' => 999], ['mongo@database-mongo']),
            'mongo-schemaless' => new self($name, 'Tests\\E2E\\Adapter\\Schemaless\\MongoDBTest', 'Schemaless/MongoDBTest.php', 'Mongo-Schemaless-', 'cxrecordermgl', ['engine' => 'mongo', 'schemaless' => true], ['mongo@database-mongo']),
            default => null,
        };
    }
}
