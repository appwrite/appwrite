<?php

declare(strict_types=1);

namespace Tests\Unit\Network;

use Appwrite\Network\Subnet;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SubnetTest extends TestCase
{
    #[DataProvider('memberships')]
    public function testContains(string $subnet, string $address, bool $expected): void
    {
        $this->assertSame($expected, $this->subnet($subnet)->contains($address), "{$subnet} contains {$address}");
    }

    public static function memberships(): \Iterator
    {
        yield 'v4 /8 first' => ['10.0.0.0/8', '10.0.0.0', true];
        yield 'v4 /8 last' => ['10.0.0.0/8', '10.255.255.255', true];
        yield 'v4 /8 above' => ['10.0.0.0/8', '11.0.0.0', false];
        yield 'v4 /8 below' => ['10.0.0.0/8', '9.255.255.255', false];
        yield 'v4 /32 exact' => ['10.0.0.5/32', '10.0.0.5', true];
        yield 'v4 /32 above' => ['10.0.0.5/32', '10.0.0.6', false];
        yield 'v4 /32 below' => ['10.0.0.5/32', '10.0.0.4', false];
        yield 'v4 /0 public' => ['0.0.0.0/0', '1.1.1.1', true];
        yield 'v4 /0 private' => ['0.0.0.0/0', '10.0.0.1', true];
        yield 'v4 /0 other family' => ['0.0.0.0/0', '::1', false];
        yield 'v4 host bits masked' => ['10.1.2.3/8', '10.200.0.1', true];
        yield 'v6 /64 first' => ['fd00:1:2:3::/64', 'fd00:1:2:3::', true];
        yield 'v6 /64 last' => ['fd00:1:2:3::/64', 'fd00:1:2:3:ffff:ffff:ffff:ffff', true];
        yield 'v6 /64 above' => ['fd00:1:2:3::/64', 'fd00:1:2:4::', false];
        yield 'v6 /128 exact' => ['::1/128', '::1', true];
        yield 'v6 /128 other' => ['::1/128', '::2', false];
        yield 'v6 other family' => ['::/0', '10.0.0.1', false];
        yield 'v4 bare exact' => ['10.0.0.5', '10.0.0.5', true];
        yield 'v4 bare other' => ['10.0.0.5', '10.0.0.6', false];
        yield 'v6 bare exact' => ['fd12::1', 'fd12::1', true];
        yield 'v6 bare other' => ['fd12::1', 'fd12::2', false];
        yield 'ipv4-mapped outside v4 subnet' => ['10.0.0.0/8', '::ffff:10.0.0.5', false];
    }

    #[DataProvider('invalidSubnets')]
    public function testParseRejects(string $value): void
    {
        $this->assertNotInstanceOf(Subnet::class, Subnet::parse($value), "Expected {$value} to be refused");
    }

    public static function invalidSubnets(): \Iterator
    {
        yield 'v4 prefix too long' => ['10.0.0.0/33'];
        yield 'v6 prefix too long' => ['fd00::/129'];
        yield 'negative prefix' => ['10.0.0.0/-1'];
        yield 'empty prefix' => ['10.0.0.0/'];
        yield 'second prefix' => ['10.0.0.0/8/8'];
        yield 'hex prefix' => ['10.0.0.0/0x8'];
        yield 'hostname' => ['abc/8'];
        yield 'numeric spelling' => ['127.1/8'];
        yield 'empty' => [''];
    }

    #[DataProvider('invalidAddresses')]
    public function testContainsRejectsInvalidAddresses(string $address): void
    {
        $this->assertFalse($this->subnet('0.0.0.0/0')->contains($address), "Expected {$address} to be refused");
        $this->assertFalse($this->subnet('::/0')->contains($address), "Expected {$address} to be refused");
    }

    public static function invalidAddresses(): \Iterator
    {
        yield 'empty' => [''];
        yield 'hostname' => ['abc'];
        yield 'truncated' => ['10.0.0'];
        yield 'numeric spelling' => ['127.1'];
        yield 'bracketed' => ['[::1]'];
    }

    private function subnet(string $value): Subnet
    {
        $subnet = Subnet::parse($value);
        $this->assertInstanceOf(Subnet::class, $subnet, "Expected {$value} to parse");

        return $subnet;
    }
}
