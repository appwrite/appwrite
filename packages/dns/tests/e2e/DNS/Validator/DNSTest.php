<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\DNS\Validator;

use PHPUnit\Framework\TestCase;
use Utopia\DNS\Message\Record;
use Utopia\DNS\Validator\DNS;

/**
 * DNS Validator Tests
 *
 * DNS Setup (on Appwrite Labs digital ocean team, network tab):
 *
 * certainly.caa.appwrite.org: CAA 0 issue "certainly.com"
 *
 * certainly-full.caa.appwrite.org: CAA 128 issuewild "certainly.com;account=123456;validationmethods=dns-01"
 *
 * letsencrypt.certainly.caa.appwrite.org: CAA 0 issue "letsencrypt.org"
 *
 * Note: These tests require actual DNS records to be configured. Tests may fail if DNS records are not set up.
 * For CAA tests, use 'ns1.digitalocean.com' resolver to match the configured records.
 */
final class DNSTest extends TestCase
{
    public function setUp(): void {}

    public function tearDown(): void {}

    public function testCNAME(): void
    {
        $validator = new DNS('appwrite.io', Record::TYPE_CNAME);

        $this->assertFalse($validator->isValid(''));
        $this->assertFalse($validator->isValid(null));
        $this->assertFalse($validator->isValid(false));
        $this->assertTrue($validator->isValid('cname-unit-test.appwrite.org'));
        $this->assertFalse($validator->isValid('test1.appwrite.org'));
    }

    public function testA(): void
    {
        // IPv4 for documentation purposes
        $validator = new DNS('203.0.113.1', Record::TYPE_A);

        $this->assertFalse($validator->isValid(''));
        $this->assertFalse($validator->isValid(null));
        $this->assertFalse($validator->isValid(false));
        $this->assertTrue($validator->isValid('a-unit-test.appwrite.org'));
        $this->assertFalse($validator->isValid('test1.appwrite.org'));
    }

    public function testAAAA(): void
    {
        // IPv6 for documentation purposes
        $validator = new DNS('2001:db8::1', Record::TYPE_AAAA);

        $this->assertFalse($validator->isValid(''));
        $this->assertFalse($validator->isValid(null));
        $this->assertFalse($validator->isValid(false));
        $this->assertTrue($validator->isValid('aaaa-unit-test.appwrite.org'));
        $this->assertFalse($validator->isValid('test1.appwrite.org'));
    }

    /**
     * Test CAA record validation with retry mechanism
     *
     * Note: This test may be flaky due to DNS propagation delays.
     * Consider implementing a retry mechanism if tests fail intermittently.
     */
    public function testCAA(): void
    {
        $digitalOceanIp = '172.64.52.210'; // ping ns1.digitalocean.com
        $certainly = new DNS('certainly.com', Record::TYPE_CAA, $digitalOceanIp);
        $letsencrypt = new DNS('letsencrypt.org', Record::TYPE_CAA, $digitalOceanIp);

        // No CAA record succeeds on main domain & subdomains for any issuer
        $this->assertTrue($certainly->isValid('caa.appwrite.org'));
        $this->assertTrue($certainly->isValid('sub.caa.appwrite.org'));
        $this->assertTrue($certainly->isValid('sub.sub.caa.appwrite.org'));
        $this->assertTrue($letsencrypt->isValid('caa.appwrite.org'));
        $this->assertTrue($letsencrypt->isValid('sub.caa.appwrite.org'));
        $this->assertTrue($letsencrypt->isValid('sub.sub.caa.appwrite.org'));

        // Custom flags and tag is allowed, but only for Certainly
        $this->assertTrue($certainly->isValid('certainly-full.caa.appwrite.org'));
        $this->assertFalse($letsencrypt->isValid('certainly-full.caa.appwrite.org'));

        // Custom flags&tag are not allowed if validator includes specific flags&tag
        $certainlyFull = new DNS('0 issue "certainly.com"', Record::TYPE_CAA, $digitalOceanIp);
        $this->assertFalse($certainlyFull->isValid('certainly-full.caa.appwrite.org'));

        // Custom flags&tag still allows if they match exactly
        $certainlyFull = new DNS('128 issuewild "certainly.com;account=123456;validationmethods=dns-01"', Record::TYPE_CAA, $digitalOceanIp);
        $this->assertTrue($certainlyFull->isValid('certainly-full.caa.appwrite.org'));

        // Certainly CAA allows Certainly, but not LetsEncrypt; Same for subdomains
        $this->assertTrue($certainly->isValid('certainly.caa.appwrite.org'));
        $this->assertFalse($letsencrypt->isValid('certainly.caa.appwrite.org'));
        $this->assertTrue($certainly->isValid('sub.certainly.caa.appwrite.org'));
        $this->assertFalse($letsencrypt->isValid('sub.certainly.caa.appwrite.org'));
        $this->assertTrue($certainly->isValid('sub.sub.certainly.caa.appwrite.org'));
        $this->assertFalse($letsencrypt->isValid('sub.sub.certainly.caa.appwrite.org'));

        // LetsEncrypt CAA on subdomain with parent allowing Certainly. Only LetsEncrypt is allowed; Same for subdomains
        $this->assertFalse($certainly->isValid('letsencrypt.certainly.caa.appwrite.org'));
        $this->assertTrue($letsencrypt->isValid('letsencrypt.certainly.caa.appwrite.org'));
        $this->assertFalse($certainly->isValid('sub.letsencrypt.certainly.caa.appwrite.org'));
        $this->assertTrue($letsencrypt->isValid('sub.letsencrypt.certainly.caa.appwrite.org'));
        $this->assertFalse($certainly->isValid('sub.sub.letsencrypt.certainly.caa.appwrite.org'));
        $this->assertTrue($letsencrypt->isValid('sub.sub.letsencrypt.certainly.caa.appwrite.org'));
    }
}
