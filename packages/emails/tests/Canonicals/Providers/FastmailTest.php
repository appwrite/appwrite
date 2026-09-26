<?php

namespace Utopia\Tests\Canonicals\Providers;

use PHPUnit\Framework\TestCase;
use Utopia\Emails\Canonicals\Providers\Fastmail;

class FastmailTest extends TestCase
{
    private Fastmail $provider;

    protected function setUp(): void
    {
        $this->provider = new Fastmail;
    }

    public function test_supports(): void
    {
        $this->assertTrue($this->provider->supports('fastmail.com'));
        $this->assertTrue($this->provider->supports('fastmail.fm'));
        $this->assertFalse($this->provider->supports('gmail.com'));
        $this->assertFalse($this->provider->supports('outlook.com'));
        $this->assertFalse($this->provider->supports('example.com'));
    }

    public function test_get_canonical(): void
    {
        $testCases = [
            // Fastmail preserves all characters (no subaddress or dot removal)
            ['user.name', 'fastmail.com', 'user.name', 'fastmail.com'],
            ['user.name+tag', 'fastmail.com', 'user.name+tag', 'fastmail.com'],
            ['user.name+spam', 'fastmail.com', 'user.name+spam', 'fastmail.com'],
            ['user.name+newsletter', 'fastmail.com', 'user.name+newsletter', 'fastmail.com'],
            ['user.name+work', 'fastmail.com', 'user.name+work', 'fastmail.com'],
            ['user.name+personal', 'fastmail.com', 'user.name+personal', 'fastmail.com'],
            ['user.name+test123', 'fastmail.com', 'user.name+test123', 'fastmail.com'],
            ['user.name+anything', 'fastmail.com', 'user.name+anything', 'fastmail.com'],
            ['user.name+verylongtag', 'fastmail.com', 'user.name+verylongtag', 'fastmail.com'],
            ['user.name+tag.with.dots', 'fastmail.com', 'user.name+tag.with.dots', 'fastmail.com'],
            ['user.name+tag-with-hyphens', 'fastmail.com', 'user.name+tag-with-hyphens', 'fastmail.com'],
            ['user.name+tag_with_underscores', 'fastmail.com', 'user.name+tag_with_underscores', 'fastmail.com'],
            ['user.name+tag123', 'fastmail.com', 'user.name+tag123', 'fastmail.com'],
            ['u.s.e.r.n.a.m.e', 'fastmail.com', 'u.s.e.r.n.a.m.e', 'fastmail.com'],
            ['u.s.e.r.n.a.m.e+tag', 'fastmail.com', 'u.s.e.r.n.a.m.e+tag', 'fastmail.com'],
            ['user+', 'fastmail.com', 'user+', 'fastmail.com'],
            ['user.', 'fastmail.com', 'user.', 'fastmail.com'],
            ['.user', 'fastmail.com', '.user', 'fastmail.com'],
            ['user..name', 'fastmail.com', 'user..name', 'fastmail.com'],
            // Other Fastmail domain
            ['user.name+tag', 'fastmail.fm', 'user.name+tag', 'fastmail.com'],
            ['user.name', 'fastmail.fm', 'user.name', 'fastmail.com'],
        ];

        foreach ($testCases as [$inputLocal, $inputDomain, $expectedLocal, $expectedDomain]) {
            $result = $this->provider->getCanonical($inputLocal, $inputDomain);
            $this->assertSame($expectedLocal, $result['local'], "Failed for local: {$inputLocal}@{$inputDomain}");
            $this->assertSame($expectedDomain, $result['domain'], "Failed for domain: {$inputLocal}@{$inputDomain}");
        }
    }

    public function test_get_canonical_domain(): void
    {
        $this->assertSame('fastmail.com', $this->provider->getCanonicalDomain());
    }

    public function test_get_supported_domains(): void
    {
        $domains = $this->provider->getSupportedDomains();
        $expected = ['fastmail.com', 'fastmail.fm'];
        $this->assertSame($expected, $domains);
    }
}
