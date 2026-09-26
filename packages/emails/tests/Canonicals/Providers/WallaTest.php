<?php

namespace Utopia\Tests\Canonicals\Providers;

use PHPUnit\Framework\TestCase;
use Utopia\Emails\Canonicals\Providers\Walla;

class WallaTest extends TestCase
{
    private Walla $provider;

    protected function setUp(): void
    {
        $this->provider = new Walla;
    }

    public function test_supports(): void
    {
        $this->assertTrue($this->provider->supports('walla.co.il'));
        $this->assertTrue($this->provider->supports('walla.com'));
        $this->assertFalse($this->provider->supports('gmail.com'));
        $this->assertFalse($this->provider->supports('outlook.com'));
        $this->assertFalse($this->provider->supports('yahoo.com'));
        $this->assertFalse($this->provider->supports('example.com'));
    }

    public function test_get_canonical(): void
    {
        $testCases = [
            // walla.co.il domain
            ['user.name', 'walla.co.il', 'user.name', 'walla.co.il'],
            ['user.name+tag', 'walla.co.il', 'user.name+tag', 'walla.co.il'],
            ['user.name+spam', 'walla.co.il', 'user.name+spam', 'walla.co.il'],
            ['user.name+newsletter', 'walla.co.il', 'user.name+newsletter', 'walla.co.il'],
            ['user.name+work', 'walla.co.il', 'user.name+work', 'walla.co.il'],
            ['user.name+personal', 'walla.co.il', 'user.name+personal', 'walla.co.il'],
            ['user.name+test123', 'walla.co.il', 'user.name+test123', 'walla.co.il'],
            ['user.name+anything', 'walla.co.il', 'user.name+anything', 'walla.co.il'],
            ['user.name+verylongtag', 'walla.co.il', 'user.name+verylongtag', 'walla.co.il'],
            ['user.name+tag.with.dots', 'walla.co.il', 'user.name+tag.with.dots', 'walla.co.il'],
            ['user.name+tag-with-hyphens', 'walla.co.il', 'user.name+tag-with-hyphens', 'walla.co.il'],
            ['user.name+tag_with_underscores', 'walla.co.il', 'user.name+tag_with_underscores', 'walla.co.il'],
            ['user.name+tag123', 'walla.co.il', 'user.name+tag123', 'walla.co.il'],
            ['u.s.e.r.n.a.m.e', 'walla.co.il', 'u.s.e.r.n.a.m.e', 'walla.co.il'],
            ['u.s.e.r.n.a.m.e+tag', 'walla.co.il', 'u.s.e.r.n.a.m.e+tag', 'walla.co.il'],
            ['user+', 'walla.co.il', 'user+', 'walla.co.il'],
            ['user.', 'walla.co.il', 'user.', 'walla.co.il'],
            ['.user', 'walla.co.il', '.user', 'walla.co.il'],
            ['user..name', 'walla.co.il', 'user..name', 'walla.co.il'],
            // walla.com domain (should normalize to walla.co.il)
            ['user.name+tag', 'walla.com', 'user.name+tag', 'walla.co.il'],
            ['user.name+spam', 'walla.com', 'user.name+spam', 'walla.co.il'],
            ['user.name', 'walla.com', 'user.name', 'walla.co.il'],
            ['u.s.e.r.n.a.m.e', 'walla.com', 'u.s.e.r.n.a.m.e', 'walla.co.il'],
            ['u.s.e.r.n.a.m.e+tag', 'walla.com', 'u.s.e.r.n.a.m.e+tag', 'walla.co.il'],
        ];

        foreach ($testCases as [$inputLocal, $inputDomain, $expectedLocal, $expectedDomain]) {
            $result = $this->provider->getCanonical($inputLocal, $inputDomain);
            $this->assertSame($expectedLocal, $result['local'], "Failed for local: {$inputLocal}@{$inputDomain}");
            $this->assertSame($expectedDomain, $result['domain'], "Failed for domain: {$inputLocal}@{$inputDomain}");
        }
    }

    public function test_get_canonical_domain(): void
    {
        $this->assertSame('walla.co.il', $this->provider->getCanonicalDomain());
    }

    public function test_get_supported_domains(): void
    {
        $domains = $this->provider->getSupportedDomains();
        $this->assertSame(['walla.co.il', 'walla.com'], $domains);
    }
}
