<?php

namespace Utopia\Tests\Usage\Adapter;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Utopia\Usage\Adapter\ClickHouse as ClickHouseAdapter;

/**
 * Shared base for ClickHouse adapter integration tests.
 *
 * Holds only the reflection helpers that need access to private adapter
 * internals (database name lookup, private query() / table-name resolver).
 * Adapter construction is intentionally inlined in each test for clarity.
 */
abstract class ClickHouseTestCase extends TestCase
{
    /**
     * Read the adapter's `database` property (private).
     */
    protected function databaseName(ClickHouseAdapter $adapter): string
    {
        $reflection = new ReflectionClass($adapter);
        $prop = $reflection->getProperty('database');
        $prop->setAccessible(true);
        $value = $prop->getValue($adapter);
        return is_string($value) ? $value : '';
    }

    /**
     * Invoke a private accessor on the adapter that returns a table name
     * (e.g. getEventsTableName, getDimRollupTableName).
     *
     * @param array<int, mixed> $args
     */
    protected function resolveTableName(ClickHouseAdapter $adapter, string $accessor, array $args = []): string
    {
        $reflection = new ReflectionClass($adapter);
        $method = $reflection->getMethod($accessor);
        $method->setAccessible(true);
        $raw = $method->invokeArgs($adapter, $args);
        return is_string($raw) ? $raw : '';
    }

    /**
     * Run raw SQL via the private query() method. Mirrors what the public
     * API does internally; needed in tests for setup / cleanup statements
     * that don't fit the find / sum / purge contracts.
     *
     * @param array<string, mixed> $params
     */
    protected function queryRaw(ClickHouseAdapter $adapter, string $sql, array $params = []): string
    {
        $reflection = new ReflectionClass($adapter);
        $method = $reflection->getMethod('query');
        $method->setAccessible(true);
        $raw = $method->invoke($adapter, $sql, $params);
        return is_string($raw) ? $raw : '';
    }
}
