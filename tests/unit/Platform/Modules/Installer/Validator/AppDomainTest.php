<?php

namespace Tests\Unit\Platform\Modules\Installer\Validator;

use Appwrite\Platform\Installer\Validator\AppDomain;
use PHPUnit\Framework\TestCase;

class AppDomainTest extends TestCase
{
    protected ?AppDomain $validator = null;

    public function setUp(): void
    {
        $this->validator = new AppDomain();
    }

    public function tearDown(): void
    {
        $this->validator = null;
    }

    public function testDescription(): void
    {
        $this->assertNotEmpty($this->validator->getDescription());
        $this->assertIsString($this->validator->getDescription());
    }

    public function testIsArray(): void
    {
        $this->assertFalse($this->validator->isArray());
    }

    public function testType(): void
    {
        $this->assertEquals($this->validator::TYPE_STRING, $this->validator->getType());
    }

    public function testRejectsNonStringTypes(): void
    {
        $this->assertFalse($this->validator->isValid(null));
        $this->assertFalse($this->validator->isValid(false));
        $this->assertFalse($this->validator->isValid(true));
        $this->assertFalse($this->validator->isValid(123));
        $this->assertFalse($this->validator->isValid(12.34));
        $this->assertFalse($this->validator->isValid([]));
        $this->assertFalse($this->validator->isValid(new \stdClass()));
    }

    public function testRejectsEmptyString(): void
    {
        $this->assertFalse($this->validator->isValid(''));
    }

    public function testRejectsWhitespaceOnly(): void
    {
        $this->assertFalse($this->validator->isValid('   '));
        $this->assertFalse($this->validator->isValid("\t"));
        $this->assertFalse($this->validator->isValid("\n"));
    }

    public function testAcceptsLocalhost(): void
    {
        $this->assertTrue($this->validator->isValid('localhost'));
    }

    public function testAcceptsLocalhostWithPort(): void
    {
        $this->assertTrue($this->validator->isValid('localhost:8080'));
        $this->assertTrue($this->validator->isValid('localhost:80'));
        $this->assertTrue($this->validator->isValid('localhost:443'));
        $this->assertTrue($this->validator->isValid('localhost:1'));
        $this->assertTrue($this->validator->isValid('localhost:65535'));
    }

    public function testAcceptsValidDomains(): void
    {
        $this->assertTrue($this->validator->isValid('example.com'));
        $this->assertTrue($this->validator->isValid('sub.example.com'));
        $this->assertTrue($this->validator->isValid('deep.sub.example.com'));
        $this->assertTrue($this->validator->isValid('appwrite.io'));
        $this->assertTrue($this->validator->isValid('my-app.example.org'));
    }

    public function testAcceptsDomainsWithPort(): void
    {
        $this->assertTrue($this->validator->isValid('example.com:443'));
        $this->assertTrue($this->validator->isValid('example.com:8080'));
        $this->assertTrue($this->validator->isValid('sub.example.com:3000'));
    }

    public function testAcceptsIPv4Addresses(): void
    {
        $this->assertTrue($this->validator->isValid('127.0.0.1'));
        $this->assertTrue($this->validator->isValid('192.168.1.1'));
        $this->assertTrue($this->validator->isValid('10.0.0.1'));
        $this->assertTrue($this->validator->isValid('0.0.0.0'));
        $this->assertTrue($this->validator->isValid('255.255.255.255'));
    }

    public function testAcceptsIPv4WithPort(): void
    {
        $this->assertTrue($this->validator->isValid('127.0.0.1:8080'));
        $this->assertTrue($this->validator->isValid('192.168.1.1:443'));
        $this->assertTrue($this->validator->isValid('10.0.0.1:3000'));
    }

    public function testAcceptsIPv6BracketNotation(): void
    {
        $this->assertTrue($this->validator->isValid('[::1]'));
        $this->assertTrue($this->validator->isValid('[::1]:8080'));
        $this->assertTrue($this->validator->isValid('[2001:db8::1]'));
        $this->assertTrue($this->validator->isValid('[2001:db8::1]:443'));
        // Scoped IPv6 with zone ID is not supported by FILTER_VALIDATE_IP
        $this->assertFalse($this->validator->isValid('[fe80::1%25eth0]'));
    }

    public function testRejectsInvalidDomains(): void
    {
        $this->assertFalse($this->validator->isValid('-invalid.com'));
        $this->assertFalse($this->validator->isValid('invalid-.com'));
        $this->assertFalse($this->validator->isValid('.example.com'));
    }

    public function testRejectsInvalidPorts(): void
    {
        $this->assertFalse($this->validator->isValid('localhost:0'));
        $this->assertFalse($this->validator->isValid('localhost:65536'));
        $this->assertFalse($this->validator->isValid('localhost:99999'));
        $this->assertFalse($this->validator->isValid('localhost:abc'));
        $this->assertFalse($this->validator->isValid('localhost:-1'));
    }

    public function testRejectsMultipleColonsWithoutBrackets(): void
    {
        $this->assertFalse($this->validator->isValid('::1'));
        $this->assertFalse($this->validator->isValid('2001:db8::1'));
        $this->assertFalse($this->validator->isValid('a:b:c'));
    }

    public function testRejectsMalformedIPv6Brackets(): void
    {
        $this->assertFalse($this->validator->isValid('['));
        $this->assertFalse($this->validator->isValid('[]'));
        $this->assertFalse($this->validator->isValid('[::1'));
        $this->assertFalse($this->validator->isValid('::1]'));
        $this->assertFalse($this->validator->isValid('[invalid'));
    }

    public function testPortBoundaryValues(): void
    {
        $this->assertTrue($this->validator->isValid('localhost:1'));
        $this->assertTrue($this->validator->isValid('localhost:65535'));
        $this->assertFalse($this->validator->isValid('localhost:0'));
        $this->assertFalse($this->validator->isValid('localhost:65536'));
    }

    public function testTrimsWhitespace(): void
    {
        $this->assertTrue($this->validator->isValid('  localhost  '));
        $this->assertTrue($this->validator->isValid('  example.com  '));
    }

    public function testAcceptsEmptyPortSegment(): void
    {
        // 'localhost:' splits into host='localhost', port='' — empty port is skipped
        $this->assertTrue($this->validator->isValid('localhost:'));
    }
}
