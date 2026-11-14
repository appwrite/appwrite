<?php

declare(strict_types=1);

namespace Tests\Unit\Network\Validators;

use Appwrite\Network\Validator\HostnameOrIP;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HostnameOrIPTest extends TestCase
{
    /**
     * @return \Iterator<string, array{mixed, bool}>
     */
    public static function values(): \Iterator
    {
        yield 'hostname' => ['ldap.example.com', true];
        yield 'single label' => ['dc1', true];
        yield 'IPv4' => ['192.0.2.10', true];
        yield 'IPv6' => ['2001:db8::1', true];
        yield 'IPv6 in brackets' => ['[2001:db8::1]', true];
        yield 'IPv6 loopback' => ['::1', true];
        yield 'hostname in brackets' => ['[ldap.example.com]', false];
        yield 'space inside' => ['ldap example.com', false];
        yield 'hostname with port' => ['ldap.example.com:636', false];
        yield 'URL' => ['ldaps://ldap.example.com', false];
        yield 'IPv6 with port' => ['[2001:db8::1]:636', false];
        yield 'empty' => ['', false];
        yield 'not a string' => [123, false];
    }

    #[DataProvider('values')]
    public function testIsValid(mixed $value, bool $expected): void
    {
        $this->assertSame($expected, (new HostnameOrIP())->isValid($value));
    }

    public function testNormalizeDropsBracketsAroundIPv6(): void
    {
        $this->assertSame('2001:db8::1', HostnameOrIP::normalize('[2001:db8::1]'));
        $this->assertSame('2001:db8::1', HostnameOrIP::normalize(' 2001:db8::1 '));
        $this->assertSame('ldap.example.com', HostnameOrIP::normalize('ldap.example.com'));
        $this->assertSame('[ldap.example.com]', HostnameOrIP::normalize('[ldap.example.com]'));
    }
}
