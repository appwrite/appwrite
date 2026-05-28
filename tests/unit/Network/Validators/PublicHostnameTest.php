<?php

namespace Tests\Unit\Network\Validators;

use Appwrite\Network\Validator\PublicHostname;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PublicHostnameTest extends TestCase
{
    public function testRejectsEmptyAndNonString(): void
    {
        $validator = new PublicHostname();

        $this->assertFalse($validator->isValid(''));
        $this->assertFalse($validator->isValid(null));
        $this->assertFalse($validator->isValid(123));
        $this->assertFalse($validator->isValid([]));
    }

    #[DataProvider('privateIpv4Addresses')]
    public function testRejectsPrivateIpv4Literals(string $ip): void
    {
        $validator = new PublicHostname();

        $this->assertFalse(
            $validator->isValid($ip),
            "Expected {$ip} to be rejected as private/reserved"
        );
        $this->assertFalse(PublicHostname::isPublicIp($ip));
    }

    public static function privateIpv4Addresses(): array
    {
        return [
            'unspecified'        => ['0.0.0.0'],
            'private 10/8'       => ['10.0.0.1'],
            'private 172.16/12'  => ['172.16.5.10'],
            'private 192.168/16' => ['192.168.1.1'],
            'cgnat'              => ['100.64.0.1'],
            'loopback'           => ['127.0.0.1'],
            'link-local imds'    => ['169.254.169.254'],
            'gcp metadata'       => ['169.254.169.254'],
            'multicast'          => ['224.0.0.1'],
            'reserved 240/4'     => ['240.0.0.1'],
            'broadcast'          => ['255.255.255.255'],
            'test-net-1'         => ['192.0.2.1'],
            'test-net-2'         => ['198.51.100.1'],
            'test-net-3'         => ['203.0.113.1'],
            'benchmark'          => ['198.18.0.1'],
        ];
    }

    #[DataProvider('privateIpv6Addresses')]
    public function testRejectsPrivateIpv6Literals(string $ip): void
    {
        $validator = new PublicHostname();

        $this->assertFalse(
            $validator->isValid($ip),
            "Expected {$ip} to be rejected as private/reserved"
        );
        $this->assertFalse(PublicHostname::isPublicIp($ip));
    }

    public static function privateIpv6Addresses(): array
    {
        return [
            'loopback'                 => ['::1'],
            'unspecified'              => ['::'],
            'link-local'               => ['fe80::1'],
            'unique-local'             => ['fc00::1'],
            'unique-local fd'          => ['fd12:3456:789a::1'],
            'multicast'                => ['ff02::1'],
            'ipv4-mapped loopback'     => ['::ffff:127.0.0.1'],
            'ipv4-mapped private'     => ['::ffff:10.0.0.1'],
            'ipv4-mapped imds'         => ['::ffff:169.254.169.254'],
            '6to4 loopback'            => ['2002:7f00:1::'],
            '6to4 imds'                => ['2002:a9fe:a9fe::'],
            'teredo'                   => ['2001:0:1::1'],
            'documentation'            => ['2001:db8::1'],
        ];
    }

    #[DataProvider('publicIpAddresses')]
    public function testAcceptsPublicIpLiterals(string $ip): void
    {
        $validator = new PublicHostname();

        $this->assertTrue(
            $validator->isValid($ip),
            "Expected {$ip} to be accepted as public"
        );
        $this->assertTrue(PublicHostname::isPublicIp($ip));
    }

    public static function publicIpAddresses(): array
    {
        return [
            'google dns'    => ['8.8.8.8'],
            'cloudflare'    => ['1.1.1.1'],
            'opendns'       => ['208.67.222.222'],
            'public ipv6'   => ['2606:4700:4700::1111'],
        ];
    }

    public function testRejectsMalformedInput(): void
    {
        $validator = new PublicHostname();

        $this->assertFalse($validator->isValid('not a hostname at all'));
        $this->assertFalse($validator->isValid('http://example.com'));
        $this->assertFalse($validator->isValid('999.999.999.999'));
    }

    public function testRejectsNonResolvingHostname(): void
    {
        $validator = new PublicHostname();

        // RFC 2606 reserves this for testing — guaranteed not to resolve.
        $this->assertFalse($validator->isValid('a-hostname-that-does-not-exist.invalid'));
    }

    public function testReasonIsPopulatedOnFailure(): void
    {
        $validator = new PublicHostname();
        $validator->isValid('127.0.0.1');

        $this->assertStringContainsString('127.0.0.1', $validator->getDescription());
        $this->assertStringContainsString('private', $validator->getDescription());
    }

    public function testReasonResetsBetweenCalls(): void
    {
        $validator = new PublicHostname();

        $validator->isValid('');
        $this->assertSame('Hostname is empty.', $validator->getDescription());

        $validator->isValid('127.0.0.1');
        $this->assertStringContainsString('127.0.0.1', $validator->getDescription());
    }

    public function testIpInCidrEdgesViaPublicIp(): void
    {
        // Boundary checks: first and last address of common ranges.
        $this->assertFalse(PublicHostname::isPublicIp('10.0.0.0'));
        $this->assertFalse(PublicHostname::isPublicIp('10.255.255.255'));
        $this->assertTrue(PublicHostname::isPublicIp('11.0.0.0'));
        $this->assertTrue(PublicHostname::isPublicIp('9.255.255.255'));

        $this->assertFalse(PublicHostname::isPublicIp('169.254.0.0'));
        $this->assertFalse(PublicHostname::isPublicIp('169.254.255.255'));
        $this->assertTrue(PublicHostname::isPublicIp('169.253.255.255'));
        $this->assertTrue(PublicHostname::isPublicIp('169.255.0.0'));

        $this->assertFalse(PublicHostname::isPublicIp('100.64.0.0'));
        $this->assertFalse(PublicHostname::isPublicIp('100.127.255.255'));
        $this->assertTrue(PublicHostname::isPublicIp('100.128.0.0'));
        $this->assertTrue(PublicHostname::isPublicIp('100.63.255.255'));
    }
}
