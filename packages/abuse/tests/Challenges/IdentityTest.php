<?php

namespace Utopia\Abuse\Tests\Challenges;

use PHPUnit\Framework\TestCase;
use Utopia\Abuse\Challenges\Identity;

final class IdentityTest extends TestCase
{
    public function testIpv4IsCoarsenedToSlash24(): void
    {
        $this->assertSame('203.0.113.0/24', Identity::ipPrefix('203.0.113.45'));
    }

    public function testDifferentHostsInSameSubnetShareThePrefix(): void
    {
        // Two devices behind the same /24 (e.g. same office egress) map to one
        // prefix — that is what lets a clearance survive minor IP drift, and also
        // what a copied cookie is bound to.
        $this->assertSame(
            Identity::ipPrefix('203.0.113.45'),
            Identity::ipPrefix('203.0.113.200'),
        );
    }

    public function testDifferentSubnetsGetDifferentPrefixes(): void
    {
        $this->assertNotSame(
            Identity::ipPrefix('203.0.113.45'),
            Identity::ipPrefix('203.0.114.45'),
        );
    }

    public function testIpv6IsCoarsenedToSlash48(): void
    {
        $prefix = Identity::ipPrefix('2001:db8:abcd:1234:5678:9abc:def0:1234');
        $this->assertStringEndsWith('/48', $prefix);
        // Same /48, different lower bits → identical prefix.
        $this->assertSame($prefix, Identity::ipPrefix('2001:db8:abcd:ffff:ffff:ffff:ffff:ffff'));
    }

    public function testEmptyIpReturnsEmpty(): void
    {
        $this->assertSame('', Identity::ipPrefix(''));
        $this->assertSame('', Identity::ipPrefix('   '));
    }

    public function testMalformedIpFallsBackToRawValue(): void
    {
        $this->assertSame('not-an-ip', Identity::ipPrefix('not-an-ip'));
    }

    public function testUaHashIsDeterministicAndDistinct(): void
    {
        $a = Identity::uaHash('Mozilla/5.0 Chrome/150');
        $this->assertSame($a, Identity::uaHash('Mozilla/5.0 Chrome/150'));
        $this->assertNotSame($a, Identity::uaHash('Mozilla/5.0 Firefox/130'));
        $this->assertSame(64, \strlen($a)); // sha256 hex
    }
}
