<?php

declare(strict_types=1);

namespace Utopia\Validator\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Validator;
use Utopia\Validator\Allowlist;
use Utopia\Validator\Subnet;

final class AllowlistTest extends TestCase
{
    public function testDescribesAStringValidator(): void
    {
        $validator = new Allowlist();

        $this->assertSame(Validator::TYPE_STRING, $validator->getType());
        $this->assertFalse($validator->isArray());
        $this->assertNotSame('', $validator->getDescription());
    }

    public function testDefaultAllowsNothing(): void
    {
        $allowlist = new Allowlist();

        $this->assertFalse($allowlist->hasSubnets());
        $this->assertFalse($allowlist->hasHostname('appwrite'));
        $this->assertFalse($allowlist->hasAddress('10.0.0.1'));
        $this->assertFalse($allowlist->isValid('appwrite'));
        $this->assertFalse($allowlist->isValid('10.0.0.1'));
    }

    public function testMatchesExactHostnameIgnoringCaseAndTrailingDot(): void
    {
        $allowlist = new Allowlist(['Appwrite.']);

        $this->assertFalse($allowlist->hasSubnets());
        $this->assertTrue($allowlist->hasHostname('appwrite'));
        $this->assertTrue($allowlist->hasHostname('APPWRITE'));
        $this->assertTrue($allowlist->hasHostname('appwrite.'));
        $this->assertFalse($allowlist->hasHostname('appwritex'));
        $this->assertTrue($allowlist->isValid('Appwrite'));
    }

    public function testDoesNotMatchHostnameSuffixesOrPrefixes(): void
    {
        $allowlist = new Allowlist(['example.com']);

        $this->assertTrue($allowlist->hasHostname('example.com'));
        $this->assertFalse($allowlist->hasHostname('evil-example.com'));
        $this->assertFalse($allowlist->hasHostname('evilexample.com'));
        $this->assertFalse($allowlist->hasHostname('db.example.com'));
        $this->assertFalse($allowlist->hasHostname('example.com.evil.com'));
        $this->assertFalse(new Allowlist(['db.example.com'])->hasHostname('example.com'));
    }

    public function testMatchesIpv4AndIpv6Subnets(): void
    {
        $allowlist = new Allowlist(subnets: [new Subnet('10.0.0.0/8'), new Subnet('fd00::/8')]);

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
        $allowlist = new Allowlist(subnets: [new Subnet('10.0.0.5'), new Subnet('fd12::1')]);

        $this->assertTrue($allowlist->hasAddress('10.0.0.5'));
        $this->assertFalse($allowlist->hasAddress('10.0.0.6'));
        $this->assertTrue($allowlist->hasAddress('fd12::1'));
        $this->assertFalse($allowlist->hasAddress('fd12::2'));
    }

    public function testValidatesHostnamesAndBracketedAddresses(): void
    {
        $allowlist = new Allowlist(['appwrite'], [new Subnet('fd00::/8')]);

        $this->assertTrue($allowlist->isValid('appwrite'));
        $this->assertTrue($allowlist->isValid('fd12::1'));
        $this->assertTrue($allowlist->isValid('[fd12::1]'));
        $this->assertFalse($allowlist->isValid('[fe80::1]'));
        $this->assertFalse($allowlist->isValid('db.appwrite'));
        $this->assertFalse($allowlist->isValid(null));
        $this->assertFalse($allowlist->isValid(['appwrite']));
    }

    public function testHostnameEntriesDoNotAllowAddresses(): void
    {
        $this->assertFalse(new Allowlist(['appwrite'])->hasAddress('127.0.0.1'));
    }
}
