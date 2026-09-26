<?php

namespace Utopia\Tests\Canonicals\Providers;

use PHPUnit\Framework\TestCase;
use Utopia\Emails\Canonicals\Providers\Protonmail;

class ProtonmailTest extends TestCase
{
    private Protonmail $provider;

    protected function setUp(): void
    {
        $this->provider = new Protonmail;
    }

    public function test_supports(): void
    {
        $this->assertTrue($this->provider->supports('protonmail.com'));
        $this->assertTrue($this->provider->supports('proton.me'));
        $this->assertTrue($this->provider->supports('pm.me'));
        $this->assertTrue($this->provider->supports('protonmail.ch'));
        $this->assertFalse($this->provider->supports('gmail.com'));
        $this->assertFalse($this->provider->supports('outlook.com'));
        $this->assertFalse($this->provider->supports('example.com'));
    }

    public function test_get_canonical(): void
    {
        $testCases = [
            // ProtonMail removes plus addressing but preserves dots
            ['user.name', 'protonmail.com', 'user.name', 'protonmail.com'],
            ['user.name+tag', 'protonmail.com', 'user.name', 'protonmail.com'],
            ['user.name+spam', 'protonmail.com', 'user.name', 'protonmail.com'],
            ['user.name+newsletter', 'protonmail.com', 'user.name', 'protonmail.com'],
            ['user.name+work', 'protonmail.com', 'user.name', 'protonmail.com'],
            ['user.name+personal', 'protonmail.com', 'user.name', 'protonmail.com'],
            ['user.name+test123', 'protonmail.com', 'user.name', 'protonmail.com'],
            ['user.name+anything', 'protonmail.com', 'user.name', 'protonmail.com'],
            ['user.name+verylongtag', 'protonmail.com', 'user.name', 'protonmail.com'],
            ['user.name+tag.with.dots', 'protonmail.com', 'user.name', 'protonmail.com'],
            ['user.name+tag-with-hyphens', 'protonmail.com', 'user.name', 'protonmail.com'],
            ['user.name+tag_with_underscores', 'protonmail.com', 'user.name', 'protonmail.com'],
            ['user.name+tag123', 'protonmail.com', 'user.name', 'protonmail.com'],
            ['u.s.e.r.n.a.m.e', 'protonmail.com', 'u.s.e.r.n.a.m.e', 'protonmail.com'],
            ['u.s.e.r.n.a.m.e+tag', 'protonmail.com', 'u.s.e.r.n.a.m.e', 'protonmail.com'],
            ['user+', 'protonmail.com', 'user', 'protonmail.com'],
            ['user.', 'protonmail.com', 'user.', 'protonmail.com'],
            ['.user', 'protonmail.com', '.user', 'protonmail.com'],
            ['user..name', 'protonmail.com', 'user..name', 'protonmail.com'],
            // Other ProtonMail domains (kept as canonical, not aliases)
            ['user.name+tag', 'proton.me', 'user.name', 'proton.me'],
            ['user.name+tag', 'pm.me', 'user.name', 'pm.me'],
            ['user.name', 'proton.me', 'user.name', 'proton.me'],
            ['user.name', 'pm.me', 'user.name', 'pm.me'],
            ['user.name', 'protonmail.ch', 'user.name', 'protonmail.ch'],
            ['user.name+tag', 'protonmail.ch', 'user.name', 'protonmail.ch'],
        ];

        foreach ($testCases as [$inputLocal, $inputDomain, $expectedLocal, $expectedDomain]) {
            $result = $this->provider->getCanonical($inputLocal, $inputDomain);
            $this->assertSame($expectedLocal, $result['local'], "Failed for local: {$inputLocal}@{$inputDomain}");
            $this->assertSame($expectedDomain, $result['domain'], "Failed for domain: {$inputLocal}@{$inputDomain}");
        }
    }

    public function test_get_canonical_domain(): void
    {
        $this->assertSame('protonmail.com', $this->provider->getCanonicalDomain());
    }

    public function test_get_supported_domains(): void
    {
        $domains = $this->provider->getSupportedDomains();
        $expected = ['protonmail.com', 'proton.me', 'pm.me', 'protonmail.ch'];
        $this->assertSame($expected, $domains);
    }
}
