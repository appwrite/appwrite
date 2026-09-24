<?php

namespace Utopia\Span\Tests\Storage;

use PHPUnit\Framework\TestCase;
use Utopia\Span\Span;
use Utopia\Span\Storage\Coroutine;

class CoroutineTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension not available');
        }
    }

    public function testGetReturnsNullOutsideCoroutine(): void
    {
        $storage = new Coroutine();

        $this->assertNull($storage->get());
    }

    public function testSetDoesNothingOutsideCoroutine(): void
    {
        $storage = new Coroutine();
        $span = new Span();

        $storage->set($span);

        $this->assertNull($storage->get());
    }

    public function testSetNullDoesNothingOutsideCoroutine(): void
    {
        $storage = new Coroutine();

        $storage->set(null);

        $this->assertNull($storage->get());
    }
}
