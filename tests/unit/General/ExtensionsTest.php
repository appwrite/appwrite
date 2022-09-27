<?php

namespace Tests\Unit\General;

use PHPUnit\Framework\TestCase;

class ExtensionsTest extends TestCase
{
    public function testPHPRedis(): void
    {
        $this->assertEquals(true, extension_loaded('redis'));
    }

    public function testSwoole(): void
    {
        $this->assertEquals(true, extension_loaded('swoole'));
    }

    public function testYAML(): void
    {
        $this->assertEquals(true, extension_loaded('yaml'));
    }

    public function testOPCache(): void
    {
        $this->assertEquals(true, extension_loaded('Zend OPcache'));
    }

    public function testDOM(): void
    {
        $this->assertEquals(true, extension_loaded('dom'));
    }

    public function testPDO(): void
    {
        $this->assertEquals(true, extension_loaded('PDO'));
    }

    public function testImagick(): void
    {
        $this->assertEquals(true, extension_loaded('imagick'));
    }

    public function testJSON(): void
    {
        $this->assertEquals(true, extension_loaded('json'));
    }

    public function testCURL(): void
    {
        $this->assertEquals(true, extension_loaded('curl'));
    }

    public function testMBString(): void
    {
        $this->assertEquals(true, extension_loaded('mbstring'));
    }

    public function testOPENSSL(): void
    {
        $this->assertEquals(true, extension_loaded('openssl'));
    }

    public function testZLIB(): void
    {
        $this->assertEquals(true, extension_loaded('zlib'));
    }

    public function testSockets(): void
    {
        $this->assertEquals(true, extension_loaded('sockets'));
    }

    public function testMaxminddb(): void
    {
        $this->assertEquals(true, extension_loaded('maxminddb'));
    }
}
