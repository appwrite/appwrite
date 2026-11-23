<?php

declare(strict_types=1);

namespace Utopia\Client\Tests\Destinations;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Client\Destinations\IPRange;
use Utopia\Client\Destinations\PublicInternet;

final class PublicInternetTest extends TestCase
{
    #[DataProvider('reservedAddresses')]
    public function testRefusesReservedAddresses(string $address): void
    {
        $this->assertFalse(new PublicInternet()->allows($address));
    }

    public static function reservedAddresses(): \Iterator
    {
        yield 'unspecified' => ['0.0.0.0'];
        yield 'private 10/8' => ['10.0.0.1'];
        yield 'private 172.16/12' => ['172.16.5.10'];
        yield 'private 192.168/16' => ['192.168.1.1'];
        yield 'cgnat' => ['100.64.0.1'];
        yield 'loopback' => ['127.0.0.1'];
        yield 'link-local metadata' => ['169.254.169.254'];
        yield 'multicast' => ['224.0.0.1'];
        yield 'reserved 240/4' => ['240.0.0.1'];
        yield 'broadcast' => ['255.255.255.255'];
        yield 'test-net-1' => ['192.0.2.1'];
        yield 'test-net-2' => ['198.51.100.1'];
        yield 'test-net-3' => ['203.0.113.1'];
        yield 'benchmark' => ['198.18.0.1'];
        yield 'ipv6 loopback' => ['::1'];
        yield 'ipv6 unspecified' => ['::'];
        yield 'ipv6 link-local' => ['fe80::1'];
        yield 'ipv6 unique-local' => ['fc00::1'];
        yield 'ipv6 unique-local fd' => ['fd12:3456:789a::1'];
        yield 'ipv6 multicast' => ['ff02::1'];
        yield 'ipv4-mapped loopback' => ['::ffff:127.0.0.1'];
        yield 'ipv4-mapped private' => ['::ffff:10.0.0.1'];
        yield 'ipv4-mapped metadata' => ['::ffff:169.254.169.254'];
        yield '6to4 loopback' => ['2002:7f00:1::'];
        yield '6to4 metadata' => ['2002:a9fe:a9fe::'];
        yield 'teredo' => ['2001:0:1::1'];
        yield 'documentation' => ['2001:db8::1'];
        yield 'not an address' => ['example.com'];
    }

    #[DataProvider('publicAddresses')]
    public function testAllowsPublicAddresses(string $address): void
    {
        $this->assertTrue(new PublicInternet()->allows($address));
    }

    public static function publicAddresses(): \Iterator
    {
        yield 'google dns' => ['8.8.8.8'];
        yield 'cloudflare' => ['1.1.1.1'];
        yield 'opendns' => ['208.67.222.222'];
        yield 'public ipv6' => ['2606:4700:4700::1111'];
    }

    public function testAlsoAllowedRangesAdmitOnlyThemselves(): void
    {
        $destinations = new PublicInternet(new IPRange('10.0.0.0/8'), new IPRange('0:0:0:0:0:0:0:1'));

        $this->assertTrue($destinations->allows('10.1.2.3'));
        $this->assertTrue($destinations->allows('::1'));
        $this->assertFalse($destinations->allows('127.0.0.1'));
        $this->assertFalse($destinations->allows('192.168.1.1'));
    }

    public function testNeverPermitsAProxy(): void
    {
        $this->assertFalse(new PublicInternet()->permitsProxy());
    }
}
