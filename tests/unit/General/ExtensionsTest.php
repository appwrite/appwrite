<?php

namespace Appwrite\Tests;

use Appwrite\Network\Validator\CNAME;
use PHPUnit\Framework\TestCase;

class ExtensionsTest extends TestCase
{
    public function setUp(): void
    {
        // Core
        // ctype
        // curl
        // date
        // fileinfo
        // filter
        // ftp
        // hash
        // iconv
        // libxml
        // mysqlnd
        // pcre
        // pdo_mysql
        // pdo_sqlite
        // Phar
        // posix
        // readline
        // Reflection
        // session
        // SimpleXML
        // sockets
        // sodium
        // SPL
        // sqlite3
        // standard
        // tokenizer
        // xml
        // xmlreader
        // xmlwriter
        // zlib
    }

    public function tearDown(): void
    {
    }

    public function testPHPRedis()
    {
        $this->assertEquals(true, extension_loaded('redis'));
    }

    public function testSwoole()
    {
        $this->assertEquals(true, extension_loaded('swoole'));
    }

    public function testYAML()
    {
        $this->assertEquals(true, extension_loaded('yaml'));
    }

    public function testOPCache()
    {
        $this->assertEquals(true, extension_loaded('Zend OPcache'));
    }

    public function testDOM()
    {
        $this->assertEquals(true, extension_loaded('dom'));
    }

    public function testPDO()
    {
        $this->assertEquals(true, extension_loaded('PDO'));
    }

    public function testImagick()
    {
        $this->assertEquals(true, extension_loaded('imagick'));
    }

    public function testJSON()
    {
        $this->assertEquals(true, extension_loaded('json'));
    }

    public function testCURL()
    {
        $this->assertEquals(true, extension_loaded('curl'));
    }

    public function testMBString()
    {
        $this->assertEquals(true, extension_loaded('mbstring'));
    }

    public function testOPENSSL()
    {
        $this->assertEquals(true, extension_loaded('openssl'));
    }

    public function testZLIB()
    {
        $this->assertEquals(true, extension_loaded('zlib'));
    }

    public function testSockets()
    {
        $this->assertEquals(true, extension_loaded('sockets'));
    }

    public function testMaxminddb()
    {
        $this->assertEquals(true, extension_loaded('maxminddb'));
    }
}
