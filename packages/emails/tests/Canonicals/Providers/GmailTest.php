<?php

namespace Utopia\Tests\Canonicals\Providers;

use PHPUnit\Framework\TestCase;
use Utopia\Emails\Canonicals\Providers\Gmail;

class GmailTest extends TestCase
{
    private Gmail $provider;

    protected function setUp(): void
    {
        $this->provider = new Gmail;
    }

    public function test_supports(): void
    {
        $this->assertTrue($this->provider->supports('gmail.com'));
        $this->assertTrue($this->provider->supports('googlemail.com'));
        $this->assertFalse($this->provider->supports('outlook.com'));
        $this->assertFalse($this->provider->supports('yahoo.com'));
        $this->assertFalse($this->provider->supports('example.com'));
    }

    public function test_get_canonical(): void
    {
        $testCases = [
            ['user.name', 'gmail.com', 'username', 'gmail.com'],
            ['user.name+tag', 'gmail.com', 'username', 'gmail.com'],
            ['user.name+spam', 'gmail.com', 'username', 'gmail.com'],
            ['user.name+newsletter', 'gmail.com', 'username', 'gmail.com'],
            ['user.name+work', 'gmail.com', 'username', 'gmail.com'],
            ['user.name+personal', 'gmail.com', 'username', 'gmail.com'],
            ['user.name+test123', 'gmail.com', 'username', 'gmail.com'],
            ['user.name+anything', 'gmail.com', 'username', 'gmail.com'],
            ['user.name+verylongtag', 'gmail.com', 'username', 'gmail.com'],
            ['user.name+tag.with.dots', 'gmail.com', 'username', 'gmail.com'],
            ['user.name+tag-with-hyphens', 'gmail.com', 'username', 'gmail.com'],
            ['user.name+tag_with_underscores', 'gmail.com', 'username', 'gmail.com'],
            ['user.name+tag123', 'gmail.com', 'username', 'gmail.com'],
            ['u.s.e.r.n.a.m.e', 'gmail.com', 'username', 'gmail.com'],
            ['u.s.e.r.n.a.m.e+tag', 'gmail.com', 'username', 'gmail.com'],
            ['user+', 'gmail.com', 'user', 'gmail.com'],
            ['user.', 'gmail.com', 'user', 'gmail.com'],
            ['.user', 'gmail.com', 'user', 'gmail.com'],
            ['user..name', 'gmail.com', 'username', 'gmail.com'],
            // Googlemail domain
            ['user.name+tag', 'googlemail.com', 'username', 'gmail.com'],
            ['user.name+spam', 'googlemail.com', 'username', 'gmail.com'],
            ['user.name', 'googlemail.com', 'username', 'gmail.com'],
        ];

        foreach ($testCases as [$inputLocal, $inputDomain, $expectedLocal, $expectedDomain]) {
            $result = $this->provider->getCanonical($inputLocal, $inputDomain);
            $this->assertSame($expectedLocal, $result['local'], "Failed for local: {$inputLocal}@{$inputDomain}");
            $this->assertSame($expectedDomain, $result['domain'], "Failed for domain: {$inputLocal}@{$inputDomain}");
        }
    }

    public function test_get_canonical_domain(): void
    {
        $this->assertSame('gmail.com', $this->provider->getCanonicalDomain());
    }

    public function test_get_supported_domains(): void
    {
        $domains = $this->provider->getSupportedDomains();
        $this->assertSame(['gmail.com', 'googlemail.com'], $domains);
    }
}
