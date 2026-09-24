<?php

namespace Tests\Query\Exception;

use PHPUnit\Framework\TestCase;
use Utopia\Query\Exception;
use Utopia\Query\Exception\ValidationException;

class ValidationExceptionTest extends TestCase
{
    public function testExtendsBaseException(): void
    {
        $e = new ValidationException('test');
        $this->assertInstanceOf(Exception::class, $e);
    }

    public function testCatchAllCompatibility(): void
    {
        $this->expectException(Exception::class);
        throw new ValidationException('caught by base');
    }

    public function testMessagePreserved(): void
    {
        $e = new ValidationException('Missing table');
        $this->assertSame('Missing table', $e->getMessage());
    }
}
