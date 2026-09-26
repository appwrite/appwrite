<?php

namespace Utopia\Tests\Canonicals\Providers;

use PHPUnit\Framework\TestCase;
use Utopia\Emails\Canonicals\Providers\Yahoo;

class YahooTest extends TestCase
{
    private Yahoo $provider;

    protected function setUp(): void
    {
        $this->provider = new Yahoo;
    }

    public function test_supports(): void
    {
        $this->assertTrue($this->provider->supports('yahoo.com'));
        $this->assertTrue($this->provider->supports('yahoo.co.uk'));
        $this->assertTrue($this->provider->supports('yahoo.ca'));
        $this->assertTrue($this->provider->supports('ymail.com'));
        $this->assertTrue($this->provider->supports('rocketmail.com'));
        $this->assertFalse($this->provider->supports('gmail.com'));
        $this->assertFalse($this->provider->supports('outlook.com'));
        $this->assertFalse($this->provider->supports('example.com'));
    }

    public function test_get_canonical(): void
    {
        $testCases = [
            // Hyphen-based subaddress removal (Yahoo style)
            ['user-name', 'yahoo.com', 'user', 'yahoo.com'],
            ['user-name-tag', 'yahoo.com', 'user-name', 'yahoo.com'],
            ['user-name-spam', 'yahoo.com', 'user-name', 'yahoo.com'],
            ['user-name-newsletter', 'yahoo.com', 'user-name', 'yahoo.com'],
            ['user-name-work', 'yahoo.com', 'user-name', 'yahoo.com'],
            ['user-name-personal', 'yahoo.com', 'user-name', 'yahoo.com'],
            ['user-name-test123', 'yahoo.com', 'user-name', 'yahoo.com'],
            ['user-name-anything', 'yahoo.com', 'user-name', 'yahoo.com'],
            ['user-name-verylongtag', 'yahoo.com', 'user-name', 'yahoo.com'],
            ['user-name-tag.with.dots', 'yahoo.com', 'user-name', 'yahoo.com'],
            ['user-name-tag-with-hyphens', 'yahoo.com', 'user-name-tag-with', 'yahoo.com'],
            ['user-name-tag_with_underscores', 'yahoo.com', 'user-name', 'yahoo.com'],
            ['user-name-tag123', 'yahoo.com', 'user-name', 'yahoo.com'],
            // Multiple hyphens
            ['u-s-e-r-n-a-m-e', 'yahoo.com', 'u-s-e-r-n-a-m', 'yahoo.com'],
            ['u-s-e-r-n-a-m-e-tag', 'yahoo.com', 'u-s-e-r-n-a-m-e', 'yahoo.com'],
            // Dots are preserved for Yahoo
            ['user.name', 'yahoo.com', 'user.name', 'yahoo.com'],
            ['user.name-tag', 'yahoo.com', 'user.name', 'yahoo.com'],
            ['u.s.e.r.n.a.m.e', 'yahoo.com', 'u.s.e.r.n.a.m.e', 'yahoo.com'],
            ['u.s.e.r.n.a.m.e-tag', 'yahoo.com', 'u.s.e.r.n.a.m.e', 'yahoo.com'],
            ['user.', 'yahoo.com', 'user.', 'yahoo.com'],
            ['.user', 'yahoo.com', '.user', 'yahoo.com'],
            // Edge cases
            ['user-', 'yahoo.com', 'user', 'yahoo.com'],
            ['user--tag', 'yahoo.com', 'user-', 'yahoo.com'],
            // Other Yahoo domains
            ['user.name-tag', 'yahoo.co.uk', 'user.name', 'yahoo.com'],
            ['user.name-tag', 'yahoo.ca', 'user.name', 'yahoo.com'],
            ['user.name-tag', 'ymail.com', 'user.name', 'yahoo.com'],
            ['user.name-tag', 'rocketmail.com', 'user.name', 'yahoo.com'],
            // Additional domains from validator.js
            ['user.name-tag', 'yahoo.de', 'user.name', 'yahoo.com'],
            ['user.name-tag', 'yahoo.fr', 'user.name', 'yahoo.com'],
            ['user.name-tag', 'yahoo.in', 'user.name', 'yahoo.com'],
            ['user.name-tag', 'yahoo.it', 'user.name', 'yahoo.com'],
        ];

        foreach ($testCases as [$inputLocal, $inputDomain, $expectedLocal, $expectedDomain]) {
            $result = $this->provider->getCanonical($inputLocal, $inputDomain);
            $this->assertSame($expectedLocal, $result['local'], "Failed for local: {$inputLocal}@{$inputDomain}");
            $this->assertSame($expectedDomain, $result['domain'], "Failed for domain: {$inputLocal}@{$inputDomain}");
        }
    }

    public function test_get_canonical_domain(): void
    {
        $this->assertSame('yahoo.com', $this->provider->getCanonicalDomain());
    }

    public function test_get_supported_domains(): void
    {
        $domains = $this->provider->getSupportedDomains();
        $expected = ['yahoo.com', 'yahoo.co.uk', 'yahoo.ca', 'yahoo.de', 'yahoo.fr', 'yahoo.in', 'yahoo.it', 'ymail.com', 'rocketmail.com'];
        $this->assertSame($expected, $domains);
    }
}
