<?php

namespace Utopia\DSN\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\DSN\DSN;

class DSNTest extends TestCase
{
    private const USER = 'AKIAKEY';

    private const PASSWORD = 'SECRETKEY';

    public function testSuccess(): void
    {
        $dsn = new DSN('mariadb://user:password@localhost:3306/database?charset=utf8&timezone=UTC');
        $this->assertEquals('mariadb', $dsn->getScheme());
        $this->assertEquals('user', $dsn->getUser());
        $this->assertEquals('password', $dsn->getPassword());
        $this->assertEquals('localhost', $dsn->getHost());
        $this->assertEquals('3306', $dsn->getPort());
        $this->assertEquals('database', $dsn->getPath());
        $this->assertEquals('charset=utf8&timezone=UTC', $dsn->getQuery());
        $this->assertEquals('utf8', $dsn->getParam('charset'));
        $this->assertEquals('UTC', $dsn->getParam('timezone'));
        $this->assertEmpty($dsn->getParam('doesNotExist'));

        $dsn = new DSN('mariadb://user@localhost:3306/database?charset=utf8&timezone=UTC');
        $this->assertEquals('mariadb', $dsn->getScheme());
        $this->assertEquals('user', $dsn->getUser());
        $this->assertNull($dsn->getPassword());
        $this->assertEquals('localhost', $dsn->getHost());
        $this->assertEquals('3306', $dsn->getPort());
        $this->assertEquals('database', $dsn->getPath());
        $this->assertEquals('charset=utf8&timezone=UTC', $dsn->getQuery());
        $this->assertEquals('utf8', $dsn->getParam('charset'));
        $this->assertEquals('UTC', $dsn->getParam('timezone'));
        $this->assertEmpty($dsn->getParam('doesNotExist'));

        $dsn = new DSN('mariadb://user@localhost/database?charset=utf8&timezone=UTC');
        $this->assertEquals('mariadb', $dsn->getScheme());
        $this->assertEquals('user', $dsn->getUser());
        $this->assertNull($dsn->getPassword());
        $this->assertEquals('localhost', $dsn->getHost());
        $this->assertNull($dsn->getPort());
        $this->assertEquals('database', $dsn->getPath());
        $this->assertEquals('charset=utf8&timezone=UTC', $dsn->getQuery());
        $this->assertEquals('utf8', $dsn->getParam('charset'));
        $this->assertEquals('UTC', $dsn->getParam('timezone'));
        $this->assertEmpty($dsn->getParam('doesNotExist'));

        $dsn = new DSN('mariadb://user@localhost?charset=utf8&timezone=UTC');
        $this->assertEquals('mariadb', $dsn->getScheme());
        $this->assertEquals('user', $dsn->getUser());
        $this->assertNull($dsn->getPassword());
        $this->assertEquals('localhost', $dsn->getHost());
        $this->assertNull($dsn->getPort());
        $this->assertEmpty($dsn->getPath());
        $this->assertEquals('charset=utf8&timezone=UTC', $dsn->getQuery());
        $this->assertEquals('utf8', $dsn->getParam('charset'));
        $this->assertEquals('UTC', $dsn->getParam('timezone'));
        $this->assertEmpty($dsn->getParam('doesNotExist'));

        $dsn = new DSN('mariadb://user@localhost');
        $this->assertEquals('mariadb', $dsn->getScheme());
        $this->assertEquals('user', $dsn->getUser());
        $this->assertNull($dsn->getPassword());
        $this->assertEquals('localhost', $dsn->getHost());
        $this->assertNull($dsn->getPort());
        $this->assertEmpty($dsn->getPath());
        $this->assertNull($dsn->getQuery());

        $dsn = new DSN('mariadb://user:@localhost');
        $this->assertEquals('mariadb', $dsn->getScheme());
        $this->assertEquals('user', $dsn->getUser());
        $this->assertEmpty($dsn->getPassword());
        $this->assertEquals('localhost', $dsn->getHost());
        $this->assertNull($dsn->getPort());
        $this->assertEmpty($dsn->getPath());
        $this->assertNull($dsn->getQuery());

        $dsn = new DSN('mariadb://localhost');
        $this->assertEquals('mariadb', $dsn->getScheme());
        $this->assertEmpty($dsn->getUser());
        $this->assertEmpty($dsn->getPassword());
        $this->assertEquals('localhost', $dsn->getHost());
        $this->assertEmpty($dsn->getPort());
        $this->assertEmpty($dsn->getPath());
        $this->assertEmpty($dsn->getQuery());

        $dsn = new DSN('mysql://localhost:3306');
        $this->assertEquals('mysql', $dsn->getScheme());
        $this->assertEmpty($dsn->getUser());
        $this->assertEmpty($dsn->getPassword());
        $this->assertEquals('localhost', $dsn->getHost());
        $this->assertEquals(3306, $dsn->getPort());
        $this->assertEmpty($dsn->getPath());
        $this->assertEmpty($dsn->getQuery());

        $dsn = new DSN('s3://user:secret@host:3306/bucket?region=us-east-1');
        $this->assertEquals('s3', $dsn->getScheme());
        $this->assertEquals('user', $dsn->getUser());
        $this->assertEquals('secret', $dsn->getPassword());
        $this->assertEquals('host', $dsn->getHost());
        $this->assertEquals(3306, $dsn->getPort());
        $this->assertEquals('bucket', $dsn->getPath());
        $this->assertEquals('region=us-east-1', $dsn->getQuery());
        $this->assertEquals('us-east-1', $dsn->getParam('region'));
        $this->assertEmpty($dsn->getParam('doesNotExist'));

        $password = 'sl/sh+$@no:her';
        $encoded = \urlencode($password);
        $dsn = new DSN("sms://user:$encoded@localhost");
        $this->assertEquals('sms', $dsn->getScheme());
        $this->assertEquals('user', $dsn->getUser());
        $this->assertEquals($password, $dsn->getPassword());
        $this->assertEquals('localhost', $dsn->getHost());
        $this->assertNull($dsn->getPort());
        $this->assertEmpty($dsn->getPath());
        $this->assertNull($dsn->getQuery());

        $user = 'admin@example.com';
        $encoded = \urlencode($user);
        $dsn = new DSN("sms://$encoded@localhost");
        $this->assertEquals('sms', $dsn->getScheme());
        $this->assertEquals($user, $dsn->getUser());
        $this->assertNull($dsn->getPassword());
        $this->assertEquals('localhost', $dsn->getHost());
        $this->assertNull($dsn->getPort());
        $this->assertEmpty($dsn->getPath());
        $this->assertNull($dsn->getQuery());

        $value = 'I am 100% value=<complex>, "right"?!';
        $encoded = \urlencode($value);
        $dsn = new DSN("sms://localhost?value=$encoded");
        $this->assertEquals('sms', $dsn->getScheme());
        $this->assertNull($dsn->getUser());
        $this->assertNull($dsn->getPassword());
        $this->assertEquals('localhost', $dsn->getHost());
        $this->assertNull($dsn->getPort());
        $this->assertEmpty($dsn->getPath());
        $this->assertEquals("value=$encoded", $dsn->getQuery());
    }

    public function testGetParam(): void
    {
        $dsn = new DSN('mariadb://user:password@localhost:3306/database?charset=utf8&timezone=UTC');
        $this->assertEquals('utf8', $dsn->getParam('charset'));
        $this->assertEquals('UTC', $dsn->getParam('timezone'));
        $this->assertEmpty($dsn->getParam('doesNotExist'));
        $this->assertEquals('us-east-1', $dsn->getParam('region', 'us-east-1'));
        $this->assertEquals('us-east-2', $dsn->getParam('region', 'us-east-2'));
    }

    public function testFail(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DSN('mariadb://');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideRefusedDSNs(): array
    {
        $credentials = self::USER . ':' . self::PASSWORD;

        return [
            'unparseable' => ["s3://{$credentials}@/backups?region=us-east-1"],
            'no scheme' => ["//{$credentials}@localhost/database"],
            'no host' => ["{$credentials}@localhost/database"],
        ];
    }

    #[DataProvider('provideRefusedDSNs')]
    public function testRefusalMessageOmitsCredentials(string $dsn): void
    {
        try {
            new DSN($dsn);
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringNotContainsString(self::USER, $exception->getMessage());
            $this->assertStringNotContainsString(self::PASSWORD, $exception->getMessage());

            return;
        }

        $this->fail('The DSN was accepted');
    }

    public function testUncaughtRefusalPrintsNoCredentials(): void
    {
        $ignoreArgs = \ini_set('zend.exception_ignore_args', '0');
        $maxLength = \ini_set('zend.exception_string_param_max_len', '1000000');

        try {
            new DSN('s3://' . self::USER . ':' . self::PASSWORD . '@/backups?region=us-east-1');
            $this->fail('The DSN was accepted');
        } catch (\InvalidArgumentException $exception) {
            $printed = (string) $exception;
        } finally {
            \ini_set('zend.exception_ignore_args', $ignoreArgs);
            \ini_set('zend.exception_string_param_max_len', $maxLength);
        }

        $this->assertStringNotContainsString(self::USER, $printed);
        $this->assertStringNotContainsString(self::PASSWORD, $printed);
    }
}
