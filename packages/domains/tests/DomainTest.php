<?php

declare(strict_types=1);

/**
 * Utopia PHP Framework
 *
 *
 * @link https://github.com/utopia-php/framework
 *
 * @author Eldad Fux <eldad@appwrite.io>
 *
 * @version 1.0 RC4
 *
 * @license The MIT License (MIT) <http://www.opensource.org/licenses/mit-license.php>
 */

namespace Utopia\Tests;

use Exception;
use PHPUnit\Framework\TestCase;
use Utopia\Domains\Domain;

final class DomainTest extends TestCase
{
    public function testEdgecaseDomains(): void
    {
        $domain = new Domain('httpmydomain.com');
        $this->assertSame('httpmydomain.com', $domain->getRegisterable());
    }

    public function testEdgecaseDomainsError(): void
    {
        $this->expectException('Exception');
        $this->expectExceptionMessage("'http://httpmydomain.com' must be a valid domain or hostname");
        new Domain('http://httpmydomain.com');
    }

    public function testEdgecaseDomainsError2(): void
    {
        $this->expectException('Exception');
        $this->expectExceptionMessage("'https://httpmydomain.com' must be a valid domain or hostname");
        new Domain('https://httpmydomain.com');
    }

    public function testExampleCoUk(): void
    {
        $domain = new Domain('demo.example.co.uk');

        $this->assertSame('demo.example.co.uk', $domain->get());
        $this->assertSame('uk', $domain->getTLD());
        $this->assertSame('example.co.uk', $domain->getApex());
        $this->assertSame('co.uk', $domain->getSuffix());
        $this->assertSame('example.co.uk', $domain->getRegisterable());
        $this->assertSame('example', $domain->getName());
        $this->assertSame('demo', $domain->getSub());
        $this->assertTrue($domain->isKnown());
        $this->assertTrue($domain->isICANN());
        $this->assertFalse($domain->isPrivate());
        $this->assertFalse($domain->isTest());
    }

    public function testSubSubExampleCoUk(): void
    {
        $domain = new Domain('subsub.demo.example.co.uk');

        $this->assertSame('subsub.demo.example.co.uk', $domain->get());
        $this->assertSame('uk', $domain->getTLD());
        $this->assertSame('example.co.uk', $domain->getApex());
        $this->assertSame('co.uk', $domain->getSuffix());
        $this->assertSame('example.co.uk', $domain->getRegisterable());
        $this->assertSame('example', $domain->getName());
        $this->assertSame('subsub.demo', $domain->getSub());
        $this->assertTrue($domain->isKnown());
        $this->assertTrue($domain->isICANN());
        $this->assertFalse($domain->isPrivate());
        $this->assertFalse($domain->isTest());
    }

    public function testLocalhost(): void
    {
        $domain = new Domain('localhost');

        $this->assertSame('localhost', $domain->get());
        $this->assertSame('localhost', $domain->getTLD());
        $this->assertSame('', $domain->getSuffix());
        $this->assertSame('', $domain->getRegisterable());
        $this->assertSame('', $domain->getName());
        $this->assertSame('', $domain->getSub());
        $this->assertFalse($domain->isKnown());
        $this->assertFalse($domain->isICANN());
        $this->assertFalse($domain->isPrivate());
        $this->assertTrue($domain->isTest());
    }

    public function testDemoLocalhost(): void
    {
        $domain = new Domain('demo.localhost');

        $this->assertSame('demo.localhost', $domain->get());
        $this->assertSame('localhost', $domain->getTLD());
        $this->assertSame('', $domain->getSuffix());
        $this->assertSame('', $domain->getRegisterable());
        $this->assertSame('demo', $domain->getName());
        $this->assertSame('', $domain->getSub());
        $this->assertFalse($domain->isKnown());
        $this->assertFalse($domain->isICANN());
        $this->assertFalse($domain->isPrivate());
        $this->assertTrue($domain->isTest());
    }

    public function testSubSubDemoLocalhost(): void
    {
        $domain = new Domain('sub.sub.demo.localhost');

        $this->assertSame('sub.sub.demo.localhost', $domain->get());
        $this->assertSame('localhost', $domain->getTLD());
        $this->assertSame('', $domain->getSuffix());
        $this->assertSame('', $domain->getRegisterable());
        $this->assertSame('demo', $domain->getName());
        $this->assertSame('sub.sub', $domain->getSub());
        $this->assertFalse($domain->isKnown());
        $this->assertFalse($domain->isICANN());
        $this->assertFalse($domain->isPrivate());
        $this->assertTrue($domain->isTest());
    }

    public function testSubDemoLocalhost(): void
    {
        $domain = new Domain('sub.demo.localhost');

        $this->assertSame('sub.demo.localhost', $domain->get());
        $this->assertSame('localhost', $domain->getTLD());
        $this->assertSame('', $domain->getSuffix());
        $this->assertSame('', $domain->getRegisterable());
        $this->assertSame('demo', $domain->getName());
        $this->assertSame('sub', $domain->getSub());
        $this->assertFalse($domain->isKnown());
        $this->assertFalse($domain->isICANN());
        $this->assertFalse($domain->isPrivate());
        $this->assertTrue($domain->isTest());
    }

    public function testUTF(): void
    {
        $domain = new Domain('אשקלון.קום');

        $this->assertSame('אשקלון.קום', $domain->get());
        $this->assertSame('קום', $domain->getTLD());
        $this->assertSame('קום', $domain->getSuffix());
        $this->assertSame('אשקלון.קום', $domain->getRegisterable());
        $this->assertSame('אשקלון', $domain->getName());
        $this->assertSame('', $domain->getSub());
        $this->assertTrue($domain->isKnown());
        $this->assertTrue($domain->isICANN());
        $this->assertFalse($domain->isPrivate());
        $this->assertFalse($domain->isTest());
    }

    public function testUTFSubdomain(): void
    {
        $domain = new Domain('חדשות.אשקלון.קום');

        $this->assertSame('חדשות.אשקלון.קום', $domain->get());
        $this->assertSame('קום', $domain->getTLD());
        $this->assertSame('קום', $domain->getSuffix());
        $this->assertSame('אשקלון.קום', $domain->getRegisterable());
        $this->assertSame('אשקלון', $domain->getName());
        $this->assertSame('חדשות', $domain->getSub());
        $this->assertTrue($domain->isKnown());
        $this->assertTrue($domain->isICANN());
        $this->assertFalse($domain->isPrivate());
        $this->assertFalse($domain->isTest());
    }

    public function testPrivateTLD(): void
    {
        $domain = new Domain('blog.potager.org');

        $this->assertSame('blog.potager.org', $domain->get());
        $this->assertSame('org', $domain->getTLD());
        $this->assertSame('blog.potager.org', $domain->getApex());
        $this->assertSame('potager.org', $domain->getSuffix());
        $this->assertSame('blog.potager.org', $domain->getRegisterable());
        $this->assertSame('blog', $domain->getName());
        $this->assertSame('', $domain->getSub());
        $this->assertTrue($domain->isKnown());
        $this->assertFalse($domain->isICANN());
        $this->assertTrue($domain->isPrivate());
        $this->assertFalse($domain->isTest());
    }

    public function testHTTPException1(): void
    {
        $this->expectException(Exception::class);

        new Domain('http://www.facbook.com');
    }

    public function testHTTPException2(): void
    {
        $this->expectException(Exception::class);

        new Domain('http://facbook.com');
    }

    public function testHTTPSException1(): void
    {
        $this->expectException(Exception::class);

        new Domain('https://www.facbook.com');
    }

    public function testHTTPSException2(): void
    {
        $this->expectException(Exception::class);

        new Domain('https://facbook.com');
    }

    public function testExampleExampleCk(): void
    {
        $domain = new Domain('example.example.ck');

        $this->assertSame('example.example.ck', $domain->get());
        $this->assertSame('ck', $domain->getTLD());
        $this->assertSame('example.ck', $domain->getSuffix());
        $this->assertSame('example.example.ck', $domain->getApex());
        $this->assertSame('example.example.ck', $domain->getRegisterable());
        $this->assertSame('example', $domain->getName());
        $this->assertSame('', $domain->getSub());
        $this->assertTrue($domain->isKnown());
        $this->assertTrue($domain->isICANN());
        $this->assertFalse($domain->isPrivate());
        $this->assertFalse($domain->isTest());
    }

    public function testSubSubExampleExampleCk(): void
    {
        $domain = new Domain('subsub.demo.example.example.ck');

        $this->assertSame('subsub.demo.example.example.ck', $domain->get());
        $this->assertSame('ck', $domain->getTLD());
        $this->assertSame('example.example.ck', $domain->getApex());
        $this->assertSame('example.ck', $domain->getSuffix());
        $this->assertSame('example.example.ck', $domain->getRegisterable());
        $this->assertSame('example', $domain->getName());
        $this->assertSame('subsub.demo', $domain->getSub());
        $this->assertTrue($domain->isKnown());
        $this->assertTrue($domain->isICANN());
        $this->assertFalse($domain->isPrivate());
        $this->assertFalse($domain->isTest());
    }

    public function testWwwCk(): void
    {
        $domain = new Domain('www.ck');

        $this->assertSame('www.ck', $domain->get());
        $this->assertSame('ck', $domain->getTLD());
        $this->assertSame('www.ck', $domain->getApex());
        $this->assertSame('ck', $domain->getSuffix());
        $this->assertSame('www.ck', $domain->getRegisterable());
        $this->assertSame('www', $domain->getName());
        $this->assertSame('', $domain->getSub());
        $this->assertTrue($domain->isKnown());
        $this->assertTrue($domain->isICANN());
        $this->assertFalse($domain->isPrivate());
        $this->assertFalse($domain->isTest());
    }

    public function testSubSubWwwCk(): void
    {
        $domain = new Domain('subsub.demo.www.ck');

        $this->assertSame('subsub.demo.www.ck', $domain->get());
        $this->assertSame('ck', $domain->getTLD());
        $this->assertSame('www.ck', $domain->getApex());
        $this->assertSame('ck', $domain->getSuffix());
        $this->assertSame('www.ck', $domain->getRegisterable());
        $this->assertSame('www', $domain->getName());
        $this->assertSame('subsub.demo', $domain->getSub());
        $this->assertTrue($domain->isKnown());
        $this->assertTrue($domain->isICANN());
        $this->assertFalse($domain->isPrivate());
        $this->assertFalse($domain->isTest());
    }

    public function testWildcardNomBr(): void
    {
        $domain = new Domain('sub.example.com.nom.br');

        $this->assertSame('sub.example.com.nom.br', $domain->get());
        $this->assertSame('br', $domain->getTLD());
        $this->assertSame('example.com.nom.br', $domain->getApex());
        $this->assertSame('com.nom.br', $domain->getSuffix());
        $this->assertSame('example.com.nom.br', $domain->getRegisterable());
        $this->assertSame('example', $domain->getName());
        $this->assertSame('sub', $domain->getSub());
        $this->assertTrue($domain->isKnown());
        $this->assertTrue($domain->isICANN());
        $this->assertFalse($domain->isPrivate());
        $this->assertFalse($domain->isTest());
    }

    public function testWildcardKawasakiJp(): void
    {
        $domain = new Domain('sub.example.com.kawasaki.jp');

        $this->assertSame('sub.example.com.kawasaki.jp', $domain->get());
        $this->assertSame('jp', $domain->getTLD());
        $this->assertSame('example.com.kawasaki.jp', $domain->getApex());
        $this->assertSame('com.kawasaki.jp', $domain->getSuffix());
        $this->assertSame('example.com.kawasaki.jp', $domain->getRegisterable());
        $this->assertSame('example', $domain->getName());
        $this->assertSame('sub', $domain->getSub());
        $this->assertTrue($domain->isKnown());
        $this->assertTrue($domain->isICANN());
        $this->assertFalse($domain->isPrivate());
        $this->assertFalse($domain->isTest());
    }

    public function testExceptionKawasakiJp(): void
    {
        $domain = new Domain('sub.city.kawasaki.jp');

        $this->assertSame('sub.city.kawasaki.jp', $domain->get());
        $this->assertSame('jp', $domain->getTLD());
        $this->assertSame('kawasaki.jp', $domain->getSuffix());
        $this->assertSame('city.kawasaki.jp', $domain->getRegisterable());
        $this->assertSame('city', $domain->getName());
        $this->assertSame('sub', $domain->getSub());
        $this->assertTrue($domain->isKnown());
        $this->assertTrue($domain->isICANN());
        $this->assertFalse($domain->isPrivate());
        $this->assertFalse($domain->isTest());
    }

    public function testWildcardPrivateDomain(): void
    {
        $domain = new Domain('sub.example.com.dev.adobeaemcloud.com');

        $this->assertSame('sub.example.com.dev.adobeaemcloud.com', $domain->get());
        $this->assertSame('com', $domain->getTLD());
        $this->assertSame('com.dev.adobeaemcloud.com', $domain->getSuffix());
        $this->assertSame('example.com.dev.adobeaemcloud.com', $domain->getRegisterable());
        $this->assertSame('example', $domain->getName());
        $this->assertSame('sub', $domain->getSub());
        $this->assertTrue($domain->isKnown());
        $this->assertFalse($domain->isICANN());
        $this->assertTrue($domain->isPrivate());
        $this->assertFalse($domain->isTest());
    }

    public function testPrivateDomain(): void
    {
        $domain = new Domain('sub.example.adobeaemcloud.net');

        $this->assertSame('sub.example.adobeaemcloud.net', $domain->get());
        $this->assertSame('net', $domain->getTLD());
        $this->assertSame('adobeaemcloud.net', $domain->getSuffix());
        $this->assertSame('example.adobeaemcloud.net', $domain->getRegisterable());
        $this->assertSame('example', $domain->getName());
        $this->assertSame('sub', $domain->getSub());
        $this->assertTrue($domain->isKnown());
        $this->assertFalse($domain->isICANN());
        $this->assertTrue($domain->isPrivate());
        $this->assertFalse($domain->isTest());
    }
}
