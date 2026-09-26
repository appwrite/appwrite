<?php

namespace Utopia\Tests\Canonicals\Providers;

use PHPUnit\Framework\TestCase;
use Utopia\Emails\Canonicals\Providers\Outlook;

class OutlookTest extends TestCase
{
    private Outlook $provider;

    protected function setUp(): void
    {
        $this->provider = new Outlook;
    }

    public function test_supports(): void
    {
        $this->assertTrue($this->provider->supports('outlook.com'));
        $this->assertTrue($this->provider->supports('hotmail.com'));
        $this->assertTrue($this->provider->supports('live.com'));
        $this->assertTrue($this->provider->supports('outlook.co.uk'));
        $this->assertTrue($this->provider->supports('hotmail.co.uk'));
        $this->assertTrue($this->provider->supports('live.co.uk'));
        $this->assertTrue($this->provider->supports('msn.com'));
        $this->assertTrue($this->provider->supports('passport.com'));
        $this->assertTrue($this->provider->supports('outlook.de'));
        $this->assertTrue($this->provider->supports('hotmail.fr'));
        $this->assertTrue($this->provider->supports('live.it'));
        $this->assertFalse($this->provider->supports('gmail.com'));
        $this->assertFalse($this->provider->supports('yahoo.com'));
        $this->assertFalse($this->provider->supports('example.com'));
    }

    public function test_get_canonical(): void
    {
        $testCases = [
            // Plus-based subaddress removal (Outlook style)
            ['user.name+tag', 'outlook.com', 'user.name', 'outlook.com'],
            ['user.name+spam', 'outlook.com', 'user.name', 'outlook.com'],
            ['user.name+newsletter', 'outlook.com', 'user.name', 'outlook.com'],
            ['user.name+work', 'outlook.com', 'user.name', 'outlook.com'],
            ['user.name+personal', 'outlook.com', 'user.name', 'outlook.com'],
            ['user.name+test123', 'outlook.com', 'user.name', 'outlook.com'],
            ['user.name+anything', 'outlook.com', 'user.name', 'outlook.com'],
            ['user.name+verylongtag', 'outlook.com', 'user.name', 'outlook.com'],
            ['user.name+tag.with.dots', 'outlook.com', 'user.name', 'outlook.com'],
            ['user.name+tag-with-hyphens', 'outlook.com', 'user.name', 'outlook.com'],
            ['user.name+tag_with_underscores', 'outlook.com', 'user.name', 'outlook.com'],
            ['user.name+tag123', 'outlook.com', 'user.name', 'outlook.com'],
            ['u.s.e.r.n.a.m.e+tag', 'outlook.com', 'u.s.e.r.n.a.m.e', 'outlook.com'],
            ['user+', 'outlook.com', 'user', 'outlook.com'],
            // Dots are preserved for Outlook
            ['u.s.e.r.n.a.m.e', 'outlook.com', 'u.s.e.r.n.a.m.e', 'outlook.com'],
            ['user.', 'outlook.com', 'user.', 'outlook.com'],
            ['.user', 'outlook.com', '.user', 'outlook.com'],
            // Hotmail
            ['user.name+tag', 'hotmail.com', 'user.name', 'outlook.com'],
            ['user.name+spam', 'hotmail.com', 'user.name', 'outlook.com'],
            ['user.name', 'hotmail.com', 'user.name', 'outlook.com'],
            // Live
            ['user.name+tag', 'live.com', 'user.name', 'outlook.com'],
            ['user.name+spam', 'live.com', 'user.name', 'outlook.com'],
            ['user.name', 'live.com', 'user.name', 'outlook.com'],
            // UK variants
            ['user.name+tag', 'outlook.co.uk', 'user.name', 'outlook.com'],
            ['user.name+tag', 'hotmail.co.uk', 'user.name', 'outlook.com'],
            ['user.name+tag', 'live.co.uk', 'user.name', 'outlook.com'],
            ['user.name', 'outlook.co.uk', 'user.name', 'outlook.com'],
            ['user.name', 'hotmail.co.uk', 'user.name', 'outlook.com'],
            ['user.name', 'live.co.uk', 'user.name', 'outlook.com'],
            // Additional domains
            ['user.name+tag', 'msn.com', 'user.name', 'outlook.com'],
            ['user.name+tag', 'passport.com', 'user.name', 'outlook.com'],
            ['user.name+tag', 'outlook.de', 'user.name', 'outlook.com'],
            ['user.name+tag', 'hotmail.fr', 'user.name', 'outlook.com'],
            ['user.name+tag', 'live.it', 'user.name', 'outlook.com'],
        ];

        foreach ($testCases as [$inputLocal, $inputDomain, $expectedLocal, $expectedDomain]) {
            $result = $this->provider->getCanonical($inputLocal, $inputDomain);
            $this->assertSame($expectedLocal, $result['local'], "Failed for local: {$inputLocal}@{$inputDomain}");
            $this->assertSame($expectedDomain, $result['domain'], "Failed for domain: {$inputLocal}@{$inputDomain}");
        }
    }

    public function test_get_canonical_domain(): void
    {
        $this->assertSame('outlook.com', $this->provider->getCanonicalDomain());
    }

    public function test_get_supported_domains(): void
    {
        $domains = $this->provider->getSupportedDomains();
        $expected = [
            'outlook.com', 'outlook.at', 'outlook.be', 'outlook.cl', 'outlook.co.il', 'outlook.co.nz', 'outlook.co.th', 'outlook.co.uk',
            'outlook.com.ar', 'outlook.com.au', 'outlook.com.br', 'outlook.com.gr', 'outlook.com.pe', 'outlook.com.tr', 'outlook.com.vn',
            'outlook.cz', 'outlook.de', 'outlook.dk', 'outlook.es', 'outlook.fr', 'outlook.hu', 'outlook.id', 'outlook.ie',
            'outlook.in', 'outlook.it', 'outlook.jp', 'outlook.kr', 'outlook.lv', 'outlook.my', 'outlook.ph', 'outlook.pt',
            'outlook.sa', 'outlook.sg', 'outlook.sk',
            'hotmail.com', 'hotmail.at', 'hotmail.be', 'hotmail.ca', 'hotmail.cl', 'hotmail.co.il', 'hotmail.co.nz', 'hotmail.co.th', 'hotmail.co.uk',
            'hotmail.com.ar', 'hotmail.com.au', 'hotmail.com.br', 'hotmail.com.gr', 'hotmail.com.mx', 'hotmail.com.pe', 'hotmail.com.tr', 'hotmail.com.vn',
            'hotmail.cz', 'hotmail.de', 'hotmail.dk', 'hotmail.es', 'hotmail.fr', 'hotmail.hu', 'hotmail.id', 'hotmail.ie',
            'hotmail.in', 'hotmail.it', 'hotmail.jp', 'hotmail.kr', 'hotmail.lv', 'hotmail.my', 'hotmail.ph', 'hotmail.pt',
            'hotmail.sa', 'hotmail.sg', 'hotmail.sk',
            'live.com', 'live.be', 'live.co.uk', 'live.com.ar', 'live.com.mx', 'live.de', 'live.es', 'live.eu', 'live.fr', 'live.it', 'live.nl',
            'msn.com', 'passport.com',
        ];
        $this->assertSame($expected, $domains);
    }
}
