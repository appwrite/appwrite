<?php

namespace Utopia\Span\Tests\Storage;

use PHPUnit\Framework\TestCase;
use Utopia\Span\Span;
use Utopia\Span\Storage\Memory;

class MemoryTest extends TestCase
{
    public function testGetReturnsNullInitially(): void
    {
        $storage = new Memory();

        $this->assertNull($storage->get());
    }

    public function testSetAndGet(): void
    {
        $storage = new Memory();
        $span = new Span();

        $storage->set($span);

        $this->assertSame($span, $storage->get());
    }

    public function testSetNullClearsSpan(): void
    {
        $storage = new Memory();
        $span = new Span();

        $storage->set($span);
        $storage->set(null);

        $this->assertNull($storage->get());
    }

    public function testSetOverwritesPreviousSpan(): void
    {
        $storage = new Memory();
        $span1 = new Span();
        $span2 = new Span();

        $storage->set($span1);
        $storage->set($span2);

        $this->assertSame($span2, $storage->get());
    }

    public function testMultipleInstancesAreIndependent(): void
    {
        $storage1 = new Memory();
        $storage2 = new Memory();
        $span = new Span();

        $storage1->set($span);

        $this->assertSame($span, $storage1->get());
        $this->assertNull($storage2->get());
    }
}
