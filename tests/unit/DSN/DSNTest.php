<?php

namespace Tests\Unit\DSN;

use Appwrite\DSN\DSN;
use PHPUnit\Framework\TestCase;

class DSNTest extends TestCase
{
    public function testSuccess(): void
    {
        $dsn = new DSN("mariadb://user:password@localhost:3306/database?charset=utf8&timezone=UTC");
        $this->assertEquals("mariadb", $dsn->getScheme());
        $this->assertEquals("user", $dsn->getUser());
        $this->assertEquals("password", $dsn->getPassword());
        $this->assertEquals("localhost", $dsn->getHost());
        $this->assertEquals(3306, $dsn->getPort());
        $this->assertEquals("database", $dsn->getDatabase());
        $this->assertEquals("charset=utf8&timezone=UTC", $dsn->getQuery());

        $dsn = new DSN("mariadb://user@localhost:3306/database?charset=utf8&timezone=UTC");
        $this->assertEquals("mariadb", $dsn->getScheme());
        $this->assertEquals("user", $dsn->getUser());
        $this->assertNull($dsn->getPassword());
        $this->assertEquals("localhost", $dsn->getHost());
        $this->assertEquals(3306, $dsn->getPort());
        $this->assertEquals("database", $dsn->getDatabase());
        $this->assertEquals("charset=utf8&timezone=UTC", $dsn->getQuery());

        $dsn = new DSN("mariadb://user@localhost/database?charset=utf8&timezone=UTC");
        $this->assertEquals("mariadb", $dsn->getScheme());
        $this->assertEquals("user", $dsn->getUser());
        $this->assertNull($dsn->getPassword());
        $this->assertEquals("localhost", $dsn->getHost());
        $this->assertNull($dsn->getPort());
        $this->assertEquals("database", $dsn->getDatabase());
        $this->assertEquals("charset=utf8&timezone=UTC", $dsn->getQuery());

        $dsn = new DSN("mariadb://user@localhost?charset=utf8&timezone=UTC");
        $this->assertEquals("mariadb", $dsn->getScheme());
        $this->assertEquals("user", $dsn->getUser());
        $this->assertNull($dsn->getPassword());
        $this->assertEquals("localhost", $dsn->getHost());
        $this->assertNull($dsn->getPort());
        $this->assertEmpty($dsn->getDatabase());
        $this->assertEquals("charset=utf8&timezone=UTC", $dsn->getQuery());

        $dsn = new DSN("mariadb://user@localhost");
        $this->assertEquals("mariadb", $dsn->getScheme());
        $this->assertEquals("user", $dsn->getUser());
        $this->assertNull($dsn->getPassword());
        $this->assertEquals("localhost", $dsn->getHost());
        $this->assertNull($dsn->getPort());
        $this->assertEmpty($dsn->getDatabase());
        $this->assertNull($dsn->getQuery());

        $dsn = new DSN("mariadb://user:@localhost");
        $this->assertEquals("mariadb", $dsn->getScheme());
        $this->assertEquals("user", $dsn->getUser());
        $this->assertEmpty($dsn->getPassword());
        $this->assertEquals("localhost", $dsn->getHost());
        $this->assertNull($dsn->getPort());
        $this->assertEmpty($dsn->getDatabase());
        $this->assertNull($dsn->getQuery());

        $dsn = new DSN("mariadb://localhost");
        $this->assertEquals("mariadb", $dsn->getScheme());
        $this->assertNull($dsn->getUser());
        $this->assertNull($dsn->getPassword());
        $this->assertEquals("localhost", $dsn->getHost());
        $this->assertNull($dsn->getPort());
        $this->assertEmpty($dsn->getDatabase());
        $this->assertNull($dsn->getQuery());
    }

    public function testFail(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DSN("mariadb://");
    }
}
