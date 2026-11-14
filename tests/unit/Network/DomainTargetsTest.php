<?php

declare(strict_types=1);

namespace Tests\Unit\Network;

use Appwrite\Network\DomainTargets;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DomainTargetsTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: list<string>}>
     */
    public static function hostCases(): iterable
    {
        yield 'empty' => ['', []];
        yield 'whitespace and commas' => [' ,  , ', []];
        yield 'bare host' => ['fra.cloud.appwrite.io', ['fra.cloud.appwrite.io']];
        yield 'trailing fqdn dot' => ['cname.localhost.', ['cname.localhost.']];
        yield 'trimmed mixed case' => ['  Appwrite.Example.COM ', ['appwrite.example.com']];
        yield 'https URL' => ['https://fra.cloud.appwrite.io', ['fra.cloud.appwrite.io']];
        yield 'uppercase URL with port and path' => ['HTTPS://Appwrite.Example.com:8443/v1', ['appwrite.example.com']];
        yield 'host and port' => ['appwrite.example.com:8443', ['appwrite.example.com']];
        yield 'comma-separated mixed entries' => [
            'cname1.example.com, https://cname2.example.com/path , , http://, not a host',
            ['cname1.example.com', 'cname2.example.com'],
        ];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('hostCases')]
    public function testHostsAndDomainsParseConfiguredValues(string $raw, array $expected): void
    {
        $this->assertSame($expected, DomainTargets::hosts($raw));

        $domains = DomainTargets::domains($raw);
        $this->assertSame(
            $expected,
            \array_map(static fn ($domain): string => $domain->get(), $domains),
        );
    }

    public function testEntriesSplitsAndTrimsNonEmptySegments(): void
    {
        $this->assertSame(
            ['one.example.com', 'two.example.com'],
            DomainTargets::entries(' one.example.com , , two.example.com '),
        );
    }

    public function testIpv4FiltersAndTrimsValidAddresses(): void
    {
        $this->assertSame(
            ['203.0.113.10', '198.51.100.2'],
            DomainTargets::ipv4(' 203.0.113.10 , ::1, not-an-ip, 198.51.100.2 '),
        );
    }

    public function testIpv6FiltersAndTrimsValidAddresses(): void
    {
        $this->assertSame(
            ['2001:db8::1', '::1'],
            DomainTargets::ipv6(' 2001:db8::1 , 203.0.113.10, bad, ::1 '),
        );
    }
}
