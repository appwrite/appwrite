<?php

declare(strict_types=1);

namespace Tests\Unit\Network;

use Appwrite\Network\Allowlist;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AllowlistTest extends TestCase
{
    #[DataProvider('emptyLists')]
    public function testIsEmpty(string $value): void
    {
        $allowlist = Allowlist::parse($value);

        $this->assertTrue($allowlist->isEmpty());
        $this->assertFalse($allowlist->hasSubnets());
    }

    public static function emptyLists(): \Iterator
    {
        yield 'empty' => [''];
        yield 'separators only' => [' , ,'];
        yield 'invalid entries only' => ['*.example.com,10.0.0.0/33,127.1,2130706433,host:5432'];
    }

    public function testDefaultIsEmpty(): void
    {
        $allowlist = new Allowlist();

        $this->assertTrue($allowlist->isEmpty());
        $this->assertFalse($allowlist->hasHostname('appwrite'));
        $this->assertFalse($allowlist->hasAddress('10.0.0.1'));
    }

    public function testMatchesExactHostnameIgnoringCaseAndTrailingDot(): void
    {
        $allowlist = Allowlist::parse('Appwrite.');

        $this->assertFalse($allowlist->isEmpty());
        $this->assertFalse($allowlist->hasSubnets());
        $this->assertTrue($allowlist->hasHostname('appwrite'));
        $this->assertTrue($allowlist->hasHostname('APPWRITE'));
        $this->assertTrue($allowlist->hasHostname('appwrite.'));
        $this->assertFalse($allowlist->hasHostname('appwritex'));
    }

    public function testDoesNotMatchHostnameSuffixesOrPrefixes(): void
    {
        $allowlist = Allowlist::parse('example.com');

        $this->assertTrue($allowlist->hasHostname('example.com'));
        $this->assertFalse($allowlist->hasHostname('evil-example.com'));
        $this->assertFalse($allowlist->hasHostname('evilexample.com'));
        $this->assertFalse($allowlist->hasHostname('db.example.com'));
        $this->assertFalse($allowlist->hasHostname('example.com.evil.com'));
        $this->assertFalse(Allowlist::parse('db.example.com')->hasHostname('example.com'));
    }

    public function testIgnoresInvalidEntriesAndKeepsValidSiblings(): void
    {
        $allowlist = Allowlist::parse(' *.example.com , .example.com,host:5432,http://x,2130706433,127.1,10.0.0.0/33,[fd00::]/8, appwrite ,192.168.0.0/16 ');

        $this->assertTrue($allowlist->hasHostname('appwrite'));
        $this->assertTrue($allowlist->hasAddress('192.168.1.1'));

        foreach (['*.example.com', 'db.example.com', 'example.com', 'host', 'host:5432', 'http://x', 'x', '2130706433', '127.1'] as $hostname) {
            $this->assertFalse($allowlist->hasHostname($hostname), "Expected {$hostname} not to be allowed");
        }

        foreach (['127.0.0.1', '10.0.0.1', 'fd00::1'] as $address) {
            $this->assertFalse($allowlist->hasAddress($address), "Expected {$address} not to be allowed");
        }
    }

    public function testMatchesIpv4AndIpv6Subnets(): void
    {
        $allowlist = Allowlist::parse('10.0.0.0/8,fd00::/8');

        $this->assertTrue($allowlist->hasSubnets());
        $this->assertTrue($allowlist->hasAddress('10.1.2.3'));
        $this->assertTrue($allowlist->hasAddress('fd12::1'));
        $this->assertFalse($allowlist->hasAddress('192.168.1.1'));
        $this->assertFalse($allowlist->hasAddress('fe80::1'));
        $this->assertFalse($allowlist->hasAddress('::ffff:10.0.0.5'));
        $this->assertFalse($allowlist->hasHostname('10.0.0.0/8'));
    }

    public function testMatchesSingleAddresses(): void
    {
        $allowlist = Allowlist::parse('10.0.0.5,[fd12::1]');

        $this->assertTrue($allowlist->hasAddress('10.0.0.5'));
        $this->assertFalse($allowlist->hasAddress('10.0.0.6'));
        $this->assertTrue($allowlist->hasAddress('fd12::1'));
        $this->assertFalse($allowlist->hasAddress('fd12::2'));
    }

    public function testAdmitsAddressesThatArePublicOrListed(): void
    {
        $allowlist = Allowlist::parse('10.0.0.0/8');

        $this->assertTrue($allowlist->admits(['10.0.0.5']));
        $this->assertTrue($allowlist->admits(['10.0.0.5', '1.1.1.1', '2606:4700:4700::1111']));
        $this->assertFalse($allowlist->admits(['10.0.0.5', '192.168.1.1']));
        $this->assertFalse($allowlist->admits(['10.0.0.5', '::1']));
        $this->assertFalse($allowlist->admits(['1.1.1.1']));
        $this->assertFalse($allowlist->admits([]));
    }

    public function testHostnameEntriesDoNotAllowAddresses(): void
    {
        $this->assertFalse(Allowlist::parse('appwrite')->hasAddress('127.0.0.1'));
    }
}
