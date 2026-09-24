<?php

namespace Tests\Query\Builder\Feature\PostgreSQL;

use PHPUnit\Framework\TestCase;
use Tests\Query\AssertsBindingCount;
use Utopia\Query\Builder\PostgreSQL as Builder;

class AggregateFilterTest extends TestCase
{
    use AssertsBindingCount;

    public function testSelectAggregateFilterWithAliasEmitsQuotedAlias(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->selectAggregateFilter('COUNT(*)', 'status = ?', 'active_count', ['active'])
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('SELECT COUNT(*) FILTER (WHERE status = ?) AS "active_count" FROM "orders"', $result->query);
        $this->assertSame(['active'], $result->bindings);
    }

    public function testSelectAggregateFilterWithoutAliasOmitsAsClause(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->selectAggregateFilter('COUNT(*)', 'status = ?', '', ['active'])
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('SELECT COUNT(*) FILTER (WHERE status = ?) FROM "orders"', $result->query);
        $this->assertStringNotContainsString(' AS ', $result->query);
    }

    public function testSelectAggregateFilterWithNoBindingsDoesNotAddBindings(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->selectAggregateFilter('COUNT(*)', 'total > 100', 'big_count')
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('SELECT COUNT(*) FILTER (WHERE total > 100) AS "big_count" FROM "orders"', $result->query);
        $this->assertSame([], $result->bindings);
    }

    public function testMultipleAggregateFiltersCommaSeparated(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->selectAggregateFilter('COUNT(*)', 'status = ?', 'active_count', ['active'])
            ->selectAggregateFilter('COUNT(*)', 'status = ?', 'cancelled_count', ['cancelled'])
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('SELECT COUNT(*) FILTER (WHERE status = ?) AS "active_count", COUNT(*) FILTER (WHERE status = ?) AS "cancelled_count" FROM "orders"', $result->query);
        $this->assertSame(['active', 'cancelled'], $result->bindings);
    }

    public function testSelectAggregateFilterWithMultiArgAggregate(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->selectAggregateFilter('SUM("amount")', 'status = ?', 'active_total', ['active'])
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('SELECT SUM("amount") FILTER (WHERE status = ?) AS "active_total" FROM "orders"', $result->query);
    }

    public function testSelectAggregateFilterBindingsAppendInOrder(): void
    {
        $result = (new Builder())
            ->from('events')
            ->selectAggregateFilter('COUNT(*)', 'kind = ? AND level >= ?', 'hits', ['click', 2])
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame(['click', 2], $result->bindings);
    }

    public function testChainableReturnsSameInstance(): void
    {
        $builder = new Builder();
        $returned = $builder->from('orders')->selectAggregateFilter('COUNT(*)', 'status = ?', '', ['active']);

        $this->assertSame($builder, $returned);
    }
}
