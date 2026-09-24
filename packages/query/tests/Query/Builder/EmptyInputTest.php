<?php

namespace Tests\Query\Builder;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Query\Builder;
use Utopia\Query\Builder\ClickHouse;
use Utopia\Query\Builder\MariaDB;
use Utopia\Query\Builder\MongoDB;
use Utopia\Query\Builder\MySQL;
use Utopia\Query\Builder\PostgreSQL;
use Utopia\Query\Builder\SQLite;
use Utopia\Query\Exception\ValidationException;

/**
 * Pins the current behavior of empty-array inputs across every dialect.
 *
 * These tests exist so future refactors that accidentally change the
 * handling of empty arrays (e.g. starting to throw where the builder
 * was previously a no-op) fail loudly.
 */
class EmptyInputTest extends TestCase
{
    /**
     * @return list<array{0: string, 1: class-string<Builder>}>
     */
    public static function dialectProvider(): array
    {
        return [
            ['mysql', MySQL::class],
            ['mariadb', MariaDB::class],
            ['postgres', PostgreSQL::class],
            ['sqlite', SQLite::class],
            ['clickhouse', ClickHouse::class],
            ['mongodb', MongoDB::class],
        ];
    }

    /**
     * @param class-string<Builder> $class
     */
    #[DataProvider('dialectProvider')]
    public function testSelectEmptyArrayIsNoop(string $label, string $class): void
    {
        $builder = new $class();
        $result = $builder->select([])->from('t')->build();

        // select([]) does not throw and does not add bindings
        $this->assertSame([], $result->bindings);
        $this->assertNotSame('', $result->query);
    }

    /**
     * @param class-string<Builder> $class
     */
    #[DataProvider('dialectProvider')]
    public function testGroupByEmptyArrayIsNoop(string $label, string $class): void
    {
        $builder = new $class();
        $result = $builder->from('t')->groupBy([])->build();

        // groupBy([]) does not throw — the empty GROUP BY is silently dropped
        $this->assertSame([], $result->bindings);
        $this->assertNotSame('', $result->query);
    }

    /**
     * @param class-string<Builder> $class
     */
    #[DataProvider('dialectProvider')]
    public function testFilterEmptyArrayIsNoop(string $label, string $class): void
    {
        $builder = new $class();
        $result = $builder->from('t')->filter([])->build();

        // filter([]) does not throw and produces no WHERE bindings
        $this->assertSame([], $result->bindings);
        $this->assertNotSame('', $result->query);
    }

    /**
     * @param class-string<Builder> $class
     */
    #[DataProvider('dialectProvider')]
    public function testQueriesEmptyArrayIsNoop(string $label, string $class): void
    {
        $builder = new $class();
        $result = $builder->from('t')->queries([])->build();

        $this->assertSame([], $result->bindings);
        $this->assertNotSame('', $result->query);
    }

    /**
     * @param class-string<Builder> $class
     */
    #[DataProvider('dialectProvider')]
    public function testInsertWithoutSetThrows(string $label, string $class): void
    {
        $builder = new $class();
        $builder->into('t');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('No rows to insert');
        $builder->insert();
    }

    /**
     * @param class-string<Builder> $class
     */
    #[DataProvider('dialectProvider')]
    public function testInsertWithEmptySetRowThrows(string $label, string $class): void
    {
        $builder = new $class();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Cannot insert an empty row');

        $builder->into('t')->set([])->insert();
    }
}
