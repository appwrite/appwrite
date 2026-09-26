<?php

namespace Utopia\Tests\Canonicals\Providers;

use PHPUnit\Framework\TestCase;
use Utopia\Emails\Canonicals\Providers\Icloud;

class IcloudTest extends TestCase
{
    private Icloud $provider;

    protected function setUp(): void
    {
        $this->provider = new Icloud;
    }

    public function test_supports(): void
    {
        $this->assertTrue($this->provider->supports('icloud.com'));
        $this->assertTrue($this->provider->supports('me.com'));
        $this->assertTrue($this->provider->supports('mac.com'));
        $this->assertFalse($this->provider->supports('gmail.com'));
        $this->assertFalse($this->provider->supports('outlook.com'));
        $this->assertFalse($this->provider->supports('example.com'));
    }

    public function test_get_canonical(): void
    {
        $testCases = [
            // Plus-based subaddress removal (iCloud style)
            ['user.name+tag', 'icloud.com', 'user.name', 'icloud.com'],
            ['user.name+spam', 'icloud.com', 'user.name', 'icloud.com'],
            ['user.name+newsletter', 'icloud.com', 'user.name', 'icloud.com'],
            ['user.name+work', 'icloud.com', 'user.name', 'icloud.com'],
            ['user.name+personal', 'icloud.com', 'user.name', 'icloud.com'],
            ['user.name+test123', 'icloud.com', 'user.name', 'icloud.com'],
            ['user.name+anything', 'icloud.com', 'user.name', 'icloud.com'],
            ['user.name+verylongtag', 'icloud.com', 'user.name', 'icloud.com'],
            ['user.name+tag.with.dots', 'icloud.com', 'user.name', 'icloud.com'],
            ['user.name+tag-with-hyphens', 'icloud.com', 'user.name', 'icloud.com'],
            ['user.name+tag_with_underscores', 'icloud.com', 'user.name', 'icloud.com'],
            ['user.name+tag123', 'icloud.com', 'user.name', 'icloud.com'],
            ['u.s.e.r.n.a.m.e+tag', 'icloud.com', 'u.s.e.r.n.a.m.e', 'icloud.com'],
            ['user+', 'icloud.com', 'user', 'icloud.com'],
            // Dots are preserved for iCloud
            ['user.name', 'icloud.com', 'user.name', 'icloud.com'],
            ['u.s.e.r.n.a.m.e', 'icloud.com', 'u.s.e.r.n.a.m.e', 'icloud.com'],
            ['user.', 'icloud.com', 'user.', 'icloud.com'],
            ['.user', 'icloud.com', '.user', 'icloud.com'],
            // Other Apple domains
            ['user.name+tag', 'me.com', 'user.name', 'icloud.com'],
            ['user.name+tag', 'mac.com', 'user.name', 'icloud.com'],
            ['user.name', 'me.com', 'user.name', 'icloud.com'],
            ['user.name', 'mac.com', 'user.name', 'icloud.com'],
        ];

        foreach ($testCases as [$inputLocal, $inputDomain, $expectedLocal, $expectedDomain]) {
            $result = $this->provider->getCanonical($inputLocal, $inputDomain);
            $this->assertSame($expectedLocal, $result['local'], "Failed for local: {$inputLocal}@{$inputDomain}");
            $this->assertSame($expectedDomain, $result['domain'], "Failed for domain: {$inputLocal}@{$inputDomain}");
        }
    }

    public function test_get_canonical_domain(): void
    {
        $this->assertSame('icloud.com', $this->provider->getCanonicalDomain());
    }

    public function test_get_supported_domains(): void
    {
        $domains = $this->provider->getSupportedDomains();
        $expected = ['icloud.com', 'me.com', 'mac.com'];
        $this->assertSame($expected, $domains);
    }
}
