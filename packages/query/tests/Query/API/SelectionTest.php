<?php

namespace Tests\Query\API;

use PHPUnit\Framework\TestCase;
use Utopia\Query\Method;
use Utopia\Query\Query;

class SelectionTest extends TestCase
{
    public function testSelect(): void
    {
        $query = Query::select(['name', 'email']);
        $this->assertSame(Method::Select, $query->getMethod());
        $this->assertSame(['name', 'email'], $query->getValues());
    }

    public function testOrderAsc(): void
    {
        $query = Query::orderAsc('name');
        $this->assertSame(Method::OrderAsc, $query->getMethod());
        $this->assertSame('name', $query->getAttribute());
    }

    public function testOrderAscNoAttribute(): void
    {
        $query = Query::orderAsc();
        $this->assertSame('', $query->getAttribute());
    }

    public function testOrderDesc(): void
    {
        $query = Query::orderDesc('name');
        $this->assertSame(Method::OrderDesc, $query->getMethod());
        $this->assertSame('name', $query->getAttribute());
    }

    public function testOrderDescNoAttribute(): void
    {
        $query = Query::orderDesc();
        $this->assertSame('', $query->getAttribute());
    }

    public function testOrderRandom(): void
    {
        $query = Query::orderRandom();
        $this->assertSame(Method::OrderRandom, $query->getMethod());
    }

    public function testLimit(): void
    {
        $query = Query::limit(25);
        $this->assertSame(Method::Limit, $query->getMethod());
        $this->assertSame([25], $query->getValues());
    }

    public function testOffset(): void
    {
        $query = Query::offset(10);
        $this->assertSame(Method::Offset, $query->getMethod());
        $this->assertSame([10], $query->getValues());
    }

    public function testCursorAfter(): void
    {
        $query = Query::cursorAfter('doc123');
        $this->assertSame(Method::CursorAfter, $query->getMethod());
        $this->assertSame(['doc123'], $query->getValues());
    }

    public function testCursorBefore(): void
    {
        $query = Query::cursorBefore('doc123');
        $this->assertSame(Method::CursorBefore, $query->getMethod());
        $this->assertSame(['doc123'], $query->getValues());
    }
}
