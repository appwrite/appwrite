<?php

namespace Tests\E2E\Adapter;

use Redis;
use Utopia\Cache\Adapter\None as NoneCacheAdapter;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Redis as RedisAdapter;
use Utopia\Database\Database;

class RedisTest extends Base
{
    public static ?Database $database = null;
    public static ?Redis $redisClient = null;
    public static string $redisNamespace = '';
    /** @var array<int, string> Adapter-keyspace SCAN patterns the run owns, scrubbed in tearDownAfterClass. */
    protected static array $keyPatterns = [];

    public static function getAdapterName(): string
    {
        return 'redis';
    }

    /**
     * Subclasses may override to flip shared-tables/tenant on. Called once
     * before `create()` so the configured namespace and tenancy mode reach
     * the underlying adapter from the start — patching them after-the-fact
     * leaks keys under the original namespace.
     */
    protected function configureDatabase(Database $database): void
    {
        // Default: per-run unique namespace, no shared tables.
    }

    public function getDatabase(): Database
    {
        if (self::$database !== null) {
            return self::$database;
        }

        if (self::$authorization === null) {
            self::$authorization = new \Utopia\Database\Validator\Authorization();
        }

        $host = \getenv('REDIS_HOST') ?: 'redis-mirror';
        $port = (int) (\getenv('REDIS_PORT') ?: 6379);

        $client = new Redis();
        $client->connect($host, $port);
        self::$redisClient = $client;

        // Redis-as-adapter makes the Cache layer redundant — adapter reads
        // and cache reads cost the same Redis round trip, and any
        // invalidation gap between them just becomes a stale-read window.
        // None() short-circuits the cache so reads always hit Redis.
        $cache = new Cache(new NoneCacheAdapter());

        $adapter = new RedisAdapter($client);

        self::$redisNamespace = 'utopia_test_' . \uniqid();
        $database = new Database($adapter, $cache);
        $database
            ->setAuthorization(self::$authorization)
            ->setDatabase('utopiaTests')
            ->setNamespace(self::$redisNamespace);

        $this->configureDatabase($database);

        // Track every adapter-keyspace pattern this run owns so
        // tearDownAfterClass can scrub without a global FLUSH. The
        // configureDatabase() call above may have mutated the namespace
        // (shared-tables uses ''), so capture the post-configure namespace
        // too.
        self::$keyPatterns = self::buildKeyPatterns(self::$redisNamespace, $database->getNamespace(), $database->getDatabase());

        if ($database->exists()) {
            $database->delete();
        }

        $database->create();

        return self::$database = $database;
    }

    /**
     * Build SCAN MATCH patterns covering the adapter keyspace for every
     * namespace this test class actually wrote to. The two-namespace form
     * (initial + post-configure) covers the shared-tables case where
     * setNamespace('') is applied before create().
     *
     * @return array<int, string>
     */
    protected static function buildKeyPatterns(string $initialNamespace, string $effectiveNamespace, string $database): array
    {
        $patterns = [];
        $namespaces = \array_unique([$initialNamespace, $effectiveNamespace]);
        foreach ($namespaces as $namespace) {
            // Adapter writes: `KEY_PREFIX:{namespace}:{database}:*`. Empty
            // namespace produces a literal double-colon, which is a valid
            // SCAN pattern.
            $patterns[] = RedisAdapter::KEY_PREFIX . ':' . $namespace . ':' . $database . ':*';
        }
        return \array_values(\array_unique($patterns));
    }

    protected function deleteColumn(string $collection, string $column): bool
    {
        // Redis keeps no out-of-band schema; raw column drops do not apply.
        return true;
    }

    protected function deleteIndex(string $collection, string $index): bool
    {
        return true;
    }

    /**
     * Inherited test exercises the case where an INTEGER column is altered
     * to VARCHAR. Redis stores documents as JSON; type changes do not
     * retroactively recast existing values the way PDO string returns do.
     */
    public function testUpdateAttributeStructure(): void
    {
        $this->markTestSkipped(
            'Redis stores documents as JSON; type changes do not retroactively coerce existing column values the way PDO string returns do.'
        );
    }

    /**
     * Inherited test exercises VARCHAR truncation when shrinking a column
     * that holds oversize data. Redis does not enforce string sizes on disk.
     */
    public function testUpdateAttributeSize(): void
    {
        $this->markTestSkipped(
            'Redis does not enforce string size truncation when an attribute is resized smaller than existing data.'
        );
    }

    public static function tearDownAfterClass(): void
    {
        try {
            if (self::$keyPatterns !== [] && self::$redisClient instanceof Redis) {
                self::scrubKeys(self::$redisClient, self::$keyPatterns);
            }
        } finally {
            self::$database = null;
            self::$redisClient = null;
            self::$redisNamespace = '';
            self::$keyPatterns = [];
            parent::tearDownAfterClass();
        }
    }

    /**
     * @param array<int, string> $patterns
     */
    private static function scrubKeys(Redis $client, array $patterns): void
    {
        foreach ($patterns as $pattern) {
            $iterator = null;
            while (($keys = $client->scan($iterator, $pattern, 500)) !== false) {
                if (\is_array($keys) && \count($keys) > 0) {
                    $client->del($keys);
                }
                if ($iterator === 0) {
                    break;
                }
            }
        }
    }
}
