<?php

namespace Utopia\Abuse\Tests\Challenge;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Abuse\Challenge\Identity;

final class IdentityTest extends TestCase
{
    public function testIpv4IsCoarsenedToSlash24ByDefault(): void
    {
        $this->assertSame('203.0.113.0/24', new Identity()->ipPrefix('203.0.113.45'));
    }

    public function testHostsInTheSameSubnetShareThePrefix(): void
    {
        $identity = new Identity();

        $this->assertSame($identity->ipPrefix('203.0.113.45'), $identity->ipPrefix('203.0.113.200'));
        $this->assertNotSame($identity->ipPrefix('203.0.113.45'), $identity->ipPrefix('203.0.114.45'));
    }

    public function testIpv6IsCoarsenedToSlash48ByDefault(): void
    {
        $identity = new Identity();

        $this->assertSame('2001:db8:abcd::/48', $identity->ipPrefix('2001:db8:abcd:1234:5678:9abc:def0:1234'));
        $this->assertSame('2001:db8:abcd::/48', $identity->ipPrefix('2001:db8:abcd:ffff:ffff:ffff:ffff:ffff'));
    }

    public function testPrefixLengthsAreConfigurable(): void
    {
        $identity = new Identity(ipv4Bits: 20, ipv6Bits: 36);

        $this->assertSame('203.0.112.0/20', $identity->ipPrefix('203.0.127.45'));
        $this->assertSame('2001:db8:a000::/36', $identity->ipPrefix('2001:db8:abcd::1'));
        $this->assertSame('203.0.113.45/32', new Identity(ipv4Bits: 32)->ipPrefix('203.0.113.45'));
        $this->assertSame('0.0.0.0/0', new Identity(ipv4Bits: 0)->ipPrefix('203.0.113.45'));
    }

    public function testEmptyIpReturnsEmpty(): void
    {
        $this->assertSame('', new Identity()->ipPrefix(''));
        $this->assertSame('', new Identity()->ipPrefix('   '));
    }

    public function testMalformedIpFallsBackToTheRawValue(): void
    {
        $this->assertSame('not-an-ip', new Identity()->ipPrefix('not-an-ip'));
    }

    public function testUserAgentHashIsDeterministicAndDistinct(): void
    {
        $identity = new Identity();
        $hash = $identity->userAgentHash('Mozilla/5.0 Chrome/150');

        $this->assertSame($hash, $identity->userAgentHash('Mozilla/5.0 Chrome/150'));
        $this->assertNotSame($hash, $identity->userAgentHash('Mozilla/5.0 Firefox/130'));
        $this->assertSame(64, \strlen($hash));
    }

    #[DataProvider('invalidPrefixLengths')]
    public function testInvalidPrefixLengthsAreRefused(int $ipv4Bits, int $ipv6Bits): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Identity($ipv4Bits, $ipv6Bits);
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function invalidPrefixLengths(): iterable
    {
        yield 'negative ipv4' => [-1, 48];
        yield 'ipv4 wider than 32' => [33, 48];
        yield 'negative ipv6' => [24, -1];
        yield 'ipv6 wider than 128' => [24, 129];
    }
}
