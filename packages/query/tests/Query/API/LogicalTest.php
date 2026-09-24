<?php

namespace Tests\Query\API;

use PHPUnit\Framework\TestCase;
use Utopia\Query\Method;
use Utopia\Query\Query;

class LogicalTest extends TestCase
{
    public function testOr(): void
    {
        $q1 = Query::equal('name', ['John']);
        $q2 = Query::equal('name', ['Jane']);
        $query = Query::or([$q1, $q2]);
        $this->assertSame(Method::Or, $query->getMethod());
        $this->assertCount(2, $query->getValues());
    }

    public function testAnd(): void
    {
        $q1 = Query::greaterThan('age', 18);
        $q2 = Query::lessThan('age', 65);
        $query = Query::and([$q1, $q2]);
        $this->assertSame(Method::And, $query->getMethod());
        $this->assertCount(2, $query->getValues());
    }

    public function testContainsAll(): void
    {
        $query = Query::containsAll('tags', ['php', 'js']);
        $this->assertSame(Method::ContainsAll, $query->getMethod());
        $this->assertSame(['php', 'js'], $query->getValues());
    }

    public function testElemMatch(): void
    {
        $inner = [Query::equal('field', ['val'])];
        $query = Query::elemMatch('items', $inner);
        $this->assertSame(Method::ElemMatch, $query->getMethod());
        $this->assertSame('items', $query->getAttribute());
    }

    public function testOrIsNested(): void
    {
        $query = Query::or([Query::equal('x', [1])]);
        $this->assertTrue($query->isNested());
    }

    public function testAndIsNested(): void
    {
        $query = Query::and([Query::equal('x', [1])]);
        $this->assertTrue($query->isNested());
    }

    public function testElemMatchIsNested(): void
    {
        $query = Query::elemMatch('items', [Query::equal('field', ['val'])]);
        $this->assertTrue($query->isNested());
    }

    public function testEmptyAnd(): void
    {
        $query = Query::and([]);
        $this->assertSame([], $query->getValues());
    }

    public function testEmptyOr(): void
    {
        $query = Query::or([]);
        $this->assertSame([], $query->getValues());
    }

    public function testNestedAndOr(): void
    {
        $query = Query::and([
            Query::or([
                Query::equal('a', [1]),
                Query::equal('b', [2]),
            ]),
        ]);
        $values = $query->getValues();
        $this->assertCount(1, $values);
        /** @var Query $orQuery */
        $orQuery = $values[0];
        $this->assertSame(Method::Or, $orQuery->getMethod());
        $orValues = $orQuery->getValues();
        $this->assertCount(2, $orValues);
    }
}
