<?php

namespace Utopia\Tests\Canonicals\Providers;

use PHPUnit\Framework\TestCase;
use Utopia\Emails\Canonicals\Providers\Yandex;

class YandexTest extends TestCase
{
    private Yandex $provider;

    protected function setUp(): void
    {
        $this->provider = new Yandex;
    }

    public function test_supports(): void
    {
        $this->assertTrue($this->provider->supports('yandex.ru'));
        $this->assertTrue($this->provider->supports('yandex.ua'));
        $this->assertTrue($this->provider->supports('yandex.kz'));
        $this->assertTrue($this->provider->supports('yandex.com'));
        $this->assertTrue($this->provider->supports('yandex.by'));
        $this->assertTrue($this->provider->supports('ya.ru'));
        $this->assertFalse($this->provider->supports('gmail.com'));
        $this->assertFalse($this->provider->supports('outlook.com'));
        $this->assertFalse($this->provider->supports('yahoo.com'));
        $this->assertFalse($this->provider->supports('example.com'));
    }

    public function test_get_canonical(): void
    {
        $testCases = [
            // Yandex preserves all characters (no subaddress or dot removal)
            ['user.name', 'yandex.ru', 'user.name', 'yandex.ru'],
            ['user.name+tag', 'yandex.ru', 'user.name+tag', 'yandex.ru'],
            ['user.name-tag', 'yandex.ru', 'user.name-tag', 'yandex.ru'],
            ['user.name_tag', 'yandex.ru', 'user.name_tag', 'yandex.ru'],
            ['u.s.e.r.n.a.m.e', 'yandex.ru', 'u.s.e.r.n.a.m.e', 'yandex.ru'],
            ['u-s-e-r-n-a-m-e', 'yandex.ru', 'u-s-e-r-n-a-m-e', 'yandex.ru'],
            ['user.', 'yandex.ru', 'user.', 'yandex.ru'],
            ['.user', 'yandex.ru', '.user', 'yandex.ru'],
            ['user+', 'yandex.ru', 'user+', 'yandex.ru'],
            ['user-', 'yandex.ru', 'user-', 'yandex.ru'],
            // Other Yandex domains
            ['user.name+tag', 'yandex.ua', 'user.name+tag', 'yandex.ru'],
            ['user.name+tag', 'yandex.kz', 'user.name+tag', 'yandex.ru'],
            ['user.name+tag', 'yandex.com', 'user.name+tag', 'yandex.ru'],
            ['user.name+tag', 'yandex.by', 'user.name+tag', 'yandex.ru'],
            ['user.name+tag', 'ya.ru', 'user.name+tag', 'yandex.ru'],
        ];

        foreach ($testCases as [$inputLocal, $inputDomain, $expectedLocal, $expectedDomain]) {
            $result = $this->provider->getCanonical($inputLocal, $inputDomain);
            $this->assertSame($expectedLocal, $result['local'], "Failed for local: {$inputLocal}@{$inputDomain}");
            $this->assertSame($expectedDomain, $result['domain'], "Failed for domain: {$inputLocal}@{$inputDomain}");
        }
    }

    public function test_get_canonical_domain(): void
    {
        $this->assertSame('yandex.ru', $this->provider->getCanonicalDomain());
    }

    public function test_get_supported_domains(): void
    {
        $domains = $this->provider->getSupportedDomains();
        $expected = ['yandex.ru', 'yandex.ua', 'yandex.kz', 'yandex.com', 'yandex.by', 'ya.ru'];
        $this->assertSame($expected, $domains);
    }
}
