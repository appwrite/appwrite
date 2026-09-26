<?php

namespace Utopia\Tests\Canonicals\Providers;

use PHPUnit\Framework\TestCase;
use Utopia\Emails\Canonicals\Providers\Generic;

class GenericTest extends TestCase
{
    private Generic $provider;

    protected function setUp(): void
    {
        $this->provider = new Generic;
    }

    public function test_supports(): void
    {
        // Generic provider supports all domains
        $this->assertTrue($this->provider->supports('example.com'));
        $this->assertTrue($this->provider->supports('test.org'));
        $this->assertTrue($this->provider->supports('company.net'));
        $this->assertTrue($this->provider->supports('business.co.uk'));
        $this->assertTrue($this->provider->supports('gmail.com'));
        $this->assertTrue($this->provider->supports('outlook.com'));
        $this->assertTrue($this->provider->supports('any-domain.com'));
    }

    public function test_get_canonical(): void
    {
        $testCases = [
            // Generic providers preserve all characters (no subaddress or dot removal)
            ['user.name', 'example.com', 'user.name', 'example.com'],
            ['user.name+tag', 'example.com', 'user.name+tag', 'example.com'],
            ['user.name+spam', 'example.com', 'user.name+spam', 'example.com'],
            ['user.name+newsletter', 'example.com', 'user.name+newsletter', 'example.com'],
            ['user.name+work', 'example.com', 'user.name+work', 'example.com'],
            ['user.name+personal', 'example.com', 'user.name+personal', 'example.com'],
            ['user.name+test123', 'example.com', 'user.name+test123', 'example.com'],
            ['user.name+anything', 'example.com', 'user.name+anything', 'example.com'],
            ['user.name+verylongtag', 'example.com', 'user.name+verylongtag', 'example.com'],
            ['user.name+tag.with.dots', 'example.com', 'user.name+tag.with.dots', 'example.com'],
            ['user.name+tag-with-hyphens', 'example.com', 'user.name+tag-with-hyphens', 'example.com'],
            ['user.name+tag_with_underscores', 'example.com', 'user.name+tag_with_underscores', 'example.com'],
            ['user.name+tag123', 'example.com', 'user.name+tag123', 'example.com'],
            ['u.s.e.r.n.a.m.e', 'example.com', 'u.s.e.r.n.a.m.e', 'example.com'],
            ['u.s.e.r.n.a.m.e+tag', 'example.com', 'u.s.e.r.n.a.m.e+tag', 'example.com'],
            ['user-name', 'example.com', 'user-name', 'example.com'],
            ['user-name+tag', 'example.com', 'user-name+tag', 'example.com'],
            ['user+', 'example.com', 'user+', 'example.com'],
            ['user.', 'example.com', 'user.', 'example.com'],
            ['.user', 'example.com', '.user', 'example.com'],
            ['user..name', 'example.com', 'user..name', 'example.com'],
            // Test with different domains
            ['user.name+tag', 'test.org', 'user.name+tag', 'test.org'],
            ['user.name+tag', 'company.net', 'user.name+tag', 'company.net'],
            ['user.name+tag', 'business.co.uk', 'user.name+tag', 'business.co.uk'],
            ['user.name', 'test.org', 'user.name', 'test.org'],
            ['user.name', 'company.net', 'user.name', 'company.net'],
            ['user.name', 'business.co.uk', 'user.name', 'business.co.uk'],
        ];

        foreach ($testCases as [$inputLocal, $inputDomain, $expectedLocal, $expectedDomain]) {
            $result = $this->provider->getCanonical($inputLocal, $inputDomain);
            $this->assertSame($expectedLocal, $result['local'], "Failed for local: {$inputLocal}@{$inputDomain}");
            $this->assertSame($expectedDomain, $result['domain'], "Failed for domain: {$inputLocal}@{$inputDomain}");
        }
    }

    public function test_get_canonical_domain(): void
    {
        // Generic provider doesn't have a canonical domain
        $this->assertSame('', $this->provider->getCanonicalDomain());
    }

    public function test_get_supported_domains(): void
    {
        // Generic provider supports all domains
        $domains = $this->provider->getSupportedDomains();
        $this->assertSame([], $domains);
    }
}
