<?php

namespace Tests\Unit\Network\Validators;

use Appwrite\Network\Validator\DNS;
use PHPUnit\Framework\TestCase;

class DNSTest extends TestCase
{
    public function setUp(): void
    {

    }

    public function tearDown(): void
    {
    }

    public function testCNAME(): void
    {
        $validator = new DNS('appwrite.io', DNS::RECORD_CNAME);
        $this->assertEquals($validator->isValid(''), false);
        $this->assertEquals($validator->isValid(null), false);
        $this->assertEquals($validator->isValid(false), false);
        $this->assertEquals($validator->isValid('cname-unit-test.appwrite.org'), true);
        $this->assertEquals($validator->isValid('test1.appwrite.org'), false);
    }

    public function testA(): void
    {
        // IPv4 for documentation purposes
        $validator = new DNS('203.0.113.1', DNS::RECORD_A);
        $this->assertEquals($validator->isValid(''), false);
        $this->assertEquals($validator->isValid(null), false);
        $this->assertEquals($validator->isValid(false), false);
        $this->assertEquals($validator->isValid('a-unit-test.appwrite.org'), true);
        $this->assertEquals($validator->isValid('test1.appwrite.org'), false);
    }

    public function testAAAA(): void
    {
        // IPv6 for documentation purposes
        $validator = new DNS('2001:db8::1', DNS::RECORD_AAAA);
        $this->assertEquals($validator->isValid(''), false);
        $this->assertEquals($validator->isValid(null), false);
        $this->assertEquals($validator->isValid(false), false);
        $this->assertEquals($validator->isValid('aaaa-unit-test.appwrite.org'), true);
        $this->assertEquals($validator->isValid('test1.appwrite.org'), false);
    }
}
