<?php

declare(strict_types=1);

namespace Tests\Unit\Network;

use Appwrite\Network\TrustedProxies;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TrustedProxiesTest extends TestCase
{
    public function testEmptyListTrustsNobody(): void
    {
        $proxies = TrustedProxies::fromEnv('');

        $this->assertTrue($proxies->isEmpty());
        $this->assertFalse($proxies->contains('10.0.0.1'));
        $this->assertFalse($proxies->contains('127.0.0.1'));
    }

    public function testUnsetEnvironmentTrustsPrivateNetworks(): void
    {
        $previous = getenv('_APP_TRUSTED_PROXIES');
        putenv('_APP_TRUSTED_PROXIES');

        try {
            $proxies = TrustedProxies::fromEnv();

            $this->assertFalse($proxies->isEmpty());
            $this->assertTrue($proxies->contains('172.18.0.5'));
            $this->assertTrue($proxies->contains('127.0.0.1'));
            $this->assertFalse($proxies->contains('203.0.113.10'));
        } finally {
            putenv($previous === false ? '_APP_TRUSTED_PROXIES' : '_APP_TRUSTED_PROXIES=' . $previous);
        }
    }

    public function testEmptyEnvironmentTrustsNobody(): void
    {
        $previous = getenv('_APP_TRUSTED_PROXIES');
        putenv('_APP_TRUSTED_PROXIES=');

        try {
            $this->assertTrue(TrustedProxies::fromEnv()->isEmpty());
        } finally {
            putenv($previous === false ? '_APP_TRUSTED_PROXIES' : '_APP_TRUSTED_PROXIES=' . $previous);
        }
    }

    public function testFromEnvSplitsCidrs(): void
    {
        $proxies = TrustedProxies::fromEnv('10.0.0.0/8, 172.16.0.0/12');

        $this->assertTrue($proxies->contains('10.1.2.3'));
        $this->assertTrue($proxies->contains('172.18.0.5'));
        $this->assertFalse($proxies->contains('192.0.2.10'));
    }

    public function testExactIpMatch(): void
    {
        $proxies = TrustedProxies::fromEnv('203.0.113.7');

        $this->assertTrue($proxies->contains('203.0.113.7'));
        $this->assertFalse($proxies->contains('203.0.113.8'));
    }

    #[DataProvider('cidrCases')]
    public function testCidrMatch(string $cidr, string $ip, bool $expected): void
    {
        $this->assertSame($expected, TrustedProxies::fromEnv($cidr)->contains($ip));
    }

    /**
     * @return \Iterator<string, array{0: string, 1: string, 2: bool}>
     */
    public static function cidrCases(): \Iterator
    {
        yield 'v4 in range' => ['192.168.1.0/24', '192.168.1.50', true];
        yield 'v4 out of range' => ['192.168.1.0/24', '192.168.2.50', false];
        yield 'v6 in range' => ['2001:db8::/32', '2001:db8:1::1', true];
        yield 'v6 out of range' => ['2001:db8::/32', '2001:db9::1', false];
        yield 'family mismatch' => ['10.0.0.0/8', '2001:db8::1', false];
    }
}
