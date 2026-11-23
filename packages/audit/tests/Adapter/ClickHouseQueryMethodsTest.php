<?php

namespace Utopia\Audit\Tests\Adapter;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Audit\Adapter\ClickHouse;
use Utopia\Audit\Query;

/**
 * The adapter turns queries into SQL itself, case by case. A method it has no case
 * for must fail, not be skipped: skipping a filter widens the result to every row
 * the other filters allow.
 */
final class ClickHouseQueryMethodsTest extends TestCase
{
    /**
     * @param  array<Query>  $queries
     * @return array<string, mixed>
     */
    private function parse(array $queries): array
    {
        $adapter = new \ReflectionClass(ClickHouse::class)->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(ClickHouse::class, 'parseQueries');

        /** @var array<string, mixed> */
        return $method->invoke($adapter, $queries);
    }

    /**
     * @return iterable<string, array{Query}>
     */
    public static function unsupported(): iterable
    {
        yield 'or' => [Query::or([Query::equal('event', ['a']), Query::equal('event', ['b'])])];
        yield 'and' => [Query::and([Query::equal('event', ['a'])])];
        yield 'groupBy' => [Query::groupBy(['event'])];
    }

    #[DataProvider('unsupported')]
    public function testUnsupportedMethodThrowsInsteadOfBeingSkipped(Query $query): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Query method not supported by the ClickHouse audit adapter: ' . $query->getMethod()->value);

        $this->parse([Query::equal('event', ['a']), $query]);
    }

    public function testSupportedMethodsStillParse(): void
    {
        $parsed = $this->parse([
            Query::equal('event', ['user.update']),
            Query::greaterThan('time', '2026-01-01 00:00:00'),
            Query::orderDesc('time'),
            Query::limit(10),
            Query::offset(5),
        ]);

        $this->assertNotEmpty($parsed['filters']);
        $this->assertSame(10, $parsed['limit'] ?? null);
        $this->assertSame(5, $parsed['offset'] ?? null);
    }
}
