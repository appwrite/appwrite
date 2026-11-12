<?php

namespace Utopia\Query\Tests\API;

use ArrayObject;
use PHPUnit\Framework\TestCase;
use TypeError;
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

    public function testCursorAfterArray(): void
    {
        $query = Query::cursorAfter(['id' => 'doc123']);
        $this->assertSame(Method::CursorAfter, $query->getMethod());
        $this->assertSame([['id' => 'doc123']], $query->getValues());
    }

    public function testCursorAfterObject(): void
    {
        $row = new ArrayObject(['id' => 'doc123']);
        $query = Query::cursorAfter($row);
        $this->assertSame(Method::CursorAfter, $query->getMethod());
        $this->assertSame([$row], $query->getValues());
    }

    public function testCursorBeforeArray(): void
    {
        $query = Query::cursorBefore(['id' => 'doc123']);
        $this->assertSame(Method::CursorBefore, $query->getMethod());
        $this->assertSame([['id' => 'doc123']], $query->getValues());
    }

    public function testCursorBeforeObject(): void
    {
        $row = new ArrayObject(['id' => 'doc123']);
        $query = Query::cursorBefore($row);
        $this->assertSame(Method::CursorBefore, $query->getMethod());
        $this->assertSame([$row], $query->getValues());
    }

    public function testCursorRejectsScalar(): void
    {
        $this->expectException(TypeError::class);

        /** @phpstan-ignore argument.type */
        Query::cursorAfter('doc123');
    }
}
