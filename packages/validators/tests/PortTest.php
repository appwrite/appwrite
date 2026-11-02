<?php

declare(strict_types=1);

namespace Utopia\Validator\Tests;

use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Validator\Port;

final class PortTest extends TestCase
{
    public function testType(): void
    {
        $validator = new Port();

        $this->assertSame(Port::TYPE_STRING, $validator->getType());
        $this->assertFalse($validator->isArray());
        $this->assertNotEmpty($validator->getDescription());
    }

    /**
     * @return Iterator<string, array{mixed}>
     */
    public static function validProvider(): Iterator
    {
        yield 'port' => ['80'];
        yield 'integer' => [8080];
        yield 'highest port' => ['65535'];
        yield 'ipv4 binding' => ['127.0.0.1:80'];
        yield 'all interfaces' => ['0.0.0.0:443'];
        yield 'ipv6 binding' => ['[::1]:8080'];
    }

    #[DataProvider('validProvider')]
    public function testValid(mixed $value): void
    {
        $this->assertTrue(new Port()->isValid($value));
    }

    /**
     * @return Iterator<string, array{mixed}>
     */
    public static function invalidProvider(): Iterator
    {
        yield 'empty' => [''];
        yield 'zero' => ['0'];
        yield 'too high' => ['65536'];
        yield 'leading zero' => ['080'];
        yield 'negative' => ['-80'];
        yield 'letters' => ['http'];
        yield 'trailing colon' => ['127.0.0.1:'];
        yield 'missing host' => [':80'];
        yield 'hostname' => ['localhost:80'];
        yield 'invalid ipv4' => ['999.0.0.1:80'];
        yield 'unbracketed ipv6' => ['::1:80'];
        yield 'invalid ipv6' => ['[zz::1]:80'];
        yield 'container port' => ['127.0.0.1:80:80'];
        yield 'whitespace' => [' 80'];
        yield 'null' => [null];
        yield 'array' => [['80']];
    }

    #[DataProvider('invalidProvider')]
    public function testInvalid(mixed $value): void
    {
        $this->assertFalse(new Port()->isValid($value));
    }
}
