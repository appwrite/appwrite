<?php

declare(strict_types=1);

namespace Utopia\Span\Tests\Storage;

use PHPUnit\Framework\TestCase;
use Utopia\Span\Span;
use Utopia\Span\Storage\Auto;

class AutoTest extends TestCase
{
    public function testGetReturnsNullInitially(): void
    {
        $storage = new Auto();

        $this->assertNull($storage->get());
    }

    public function testSetAndGet(): void
    {
        $storage = new Auto();
        $span = new Span();

        $storage->set($span);

        $this->assertSame($span, $storage->get());
    }

    public function testSetNullClearsSpan(): void
    {
        $storage = new Auto();
        $span = new Span();

        $storage->set($span);
        $storage->set(null);

        $this->assertNull($storage->get());
    }

    public function testSetOverwritesPreviousSpan(): void
    {
        $storage = new Auto();
        $span1 = new Span();
        $span2 = new Span();

        $storage->set($span1);
        $storage->set($span2);

        $this->assertSame($span2, $storage->get());
    }

    public function testUsesMemoryStorageOutsideCoroutine(): void
    {
        $storage = new Auto();
        $span = new Span();

        // Outside of a Swoole coroutine, should use Memory storage
        $storage->set($span);

        $this->assertSame($span, $storage->get());
    }
}
