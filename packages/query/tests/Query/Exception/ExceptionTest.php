<?php

namespace Tests\Query\Exception;

use PHPUnit\Framework\TestCase;
use Utopia\Query\Exception;

class ExceptionTest extends TestCase
{
    public function testStringCodeCoercedToInt(): void
    {
        $exception = new Exception('test', '42');
        $this->assertSame(42, $exception->getCode());
    }

    public function testNonNumericStringCodeBecomesZero(): void
    {
        $exception = new Exception('test', 'abc');
        $this->assertSame(0, $exception->getCode());
    }

    public function testIntCodePassedThrough(): void
    {
        $exception = new Exception('test', 123);
        $this->assertSame(123, $exception->getCode());
    }

    public function testDefaultCodeIsZero(): void
    {
        $exception = new Exception('test');
        $this->assertSame(0, $exception->getCode());
    }

    public function testMessageIsPreserved(): void
    {
        $exception = new Exception('Something went wrong');
        $this->assertSame('Something went wrong', $exception->getMessage());
    }

    public function testPreviousExceptionPreserved(): void
    {
        $previous = new \RuntimeException('root cause');
        $exception = new Exception('wrapper', 0, $previous);
        $this->assertSame($previous, $exception->getPrevious());
    }

    public function testExtendsBaseException(): void
    {
        $exception = new Exception('test');
        $this->assertInstanceOf(\Exception::class, $exception);
    }
}
