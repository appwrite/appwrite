<?php

namespace Tests\Query\Builder\Feature\PostgreSQL;

use PHPUnit\Framework\TestCase;
use Tests\Query\AssertsBindingCount;
use Utopia\Query\Builder\PostgreSQL as Builder;

class DistinctOnTest extends TestCase
{
    use AssertsBindingCount;

    public function testDistinctOnSingleColumnEmitsDistinctOnClause(): void
    {
        $result = (new Builder())
            ->from('events')
            ->distinctOn(['user_id'])
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('SELECT DISTINCT ON ("user_id") * FROM "events"', $result->query);
    }

    public function testDistinctOnMultipleColumnsAreCommaSeparatedAndQuoted(): void
    {
        $result = (new Builder())
            ->from('events')
            ->distinctOn(['user_id', 'session_id'])
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('SELECT DISTINCT ON ("user_id", "session_id") * FROM "events"', $result->query);
    }

    public function testDistinctOnReplacesPlainSelectKeyword(): void
    {
        $result = (new Builder())
            ->from('events')
            ->select(['user_id', 'event_at'])
            ->distinctOn(['user_id'])
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('SELECT DISTINCT ON ("user_id") "user_id", "event_at" FROM "events"', $result->query);
        // Only one SELECT keyword — the DISTINCT ON prefix must replace, not prepend.
        $this->assertSame(1, \substr_count($result->query, 'SELECT '));
    }

    public function testDistinctOnEmptyArrayDoesNotEmitDistinctOn(): void
    {
        $result = (new Builder())
            ->from('events')
            ->distinctOn([])
            ->build();

        $this->assertBindingCount($result);
        $this->assertStringNotContainsString('DISTINCT ON', $result->query);
    }

    public function testDistinctOnCombinesWithOrderBy(): void
    {
        $result = (new Builder())
            ->from('events')
            ->distinctOn(['user_id'])
            ->sortAsc('event_at')
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('SELECT DISTINCT ON ("user_id") * FROM "events" ORDER BY "event_at" ASC', $result->query);
    }

    public function testDistinctOnDoesNotAddBindings(): void
    {
        $result = (new Builder())
            ->from('events')
            ->distinctOn(['user_id', 'session_id'])
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame([], $result->bindings);
    }

    public function testChainableReturnsSameInstance(): void
    {
        $builder = new Builder();
        $returned = $builder->from('events')->distinctOn(['user_id']);

        $this->assertSame($builder, $returned);
    }
}
