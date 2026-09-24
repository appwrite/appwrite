<?php

namespace Tests\Query\Exception;

use PHPUnit\Framework\TestCase;
use Utopia\Query\Exception;
use Utopia\Query\Exception\UnsupportedException;

class UnsupportedExceptionTest extends TestCase
{
    public function testExtendsBaseException(): void
    {
        $e = new UnsupportedException('test');
        $this->assertInstanceOf(Exception::class, $e);
    }

    public function testCatchAllCompatibility(): void
    {
        $this->expectException(Exception::class);
        throw new UnsupportedException('caught by base');
    }

    public function testMessagePreserved(): void
    {
        $e = new UnsupportedException('Not supported');
        $this->assertSame('Not supported', $e->getMessage());
    }
}
