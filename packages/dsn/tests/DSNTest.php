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

    public function testFromEncodesEachPart(): void
    {
        $dsn = DSN::from(
            scheme: 's3',
            host: 'minio.example.com',
            user: 'admin@example.com',
            password: 'sl/sh+$@no:her',
            port: 9000,
            path: 'b?url=http://169.254.169.254/latest&insecure=true#',
            params: ['region' => 'us-east-1', 'value' => 'a&b=c'],
        );

        $this->assertSame('s3', $dsn->getScheme());
        $this->assertSame('admin@example.com', $dsn->getUser());
        $this->assertSame('sl/sh+$@no:her', $dsn->getPassword());
        $this->assertSame('minio.example.com', $dsn->getHost());
        $this->assertSame('9000', $dsn->getPort());
        $this->assertSame('b?url=http://169.254.169.254/latest&insecure=true#', $dsn->getPath());
        $this->assertSame('us-east-1', $dsn->getParam('region'));
        $this->assertSame('a&b=c', $dsn->getParam('value'));
        $this->assertSame('', $dsn->getParam('url'));
        $this->assertSame('', $dsn->getParam('insecure'));
    }

    public function testFromKeepsPathSegments(): void
    {
        $dsn = DSN::from(scheme: 's3', host: 'localhost', path: 'bucket/prefix');

        $this->assertSame('bucket/prefix', $dsn->getPath());
        $this->assertSame('s3://localhost/bucket/prefix', (string) $dsn);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideReshapingHosts(): array
    {
        return [
            'path' => ['minio.example.com/other'],
            'query' => ['minio.example.com?url=http://10.0.0.1'],
            'fragment' => ['minio.example.com#x'],
            'userinfo' => ['user@minio.example.com'],
            'port' => ['minio.example.com:9000'],
        ];
    }

    #[DataProvider('provideReshapingHosts')]
    public function testFromRefusesAHostThatReshapesTheDSN(string $host): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DSN::from(scheme: 's3', host: $host, path: 'bucket');
    }

    public function testFromRefusesAPasswordWithoutAUser(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DSN::from(scheme: 's3', host: 'localhost', password: 'secret');
    }

    /**
     * @return array<string, array{array<string, string>}>
     */
    public static function provideParamsParseStrRewrites(): array
    {
        return [
            'brackets' => [['x[y]' => 'z']],
            'dot' => [['a.b' => 'c']],
            'space' => [['a b' => 'c']],
        ];
    }

    /**
     * @param  array<string, string>  $params
     */
    #[DataProvider('provideParamsParseStrRewrites')]
    public function testFromRefusesParamsGetParamCannotReadBack(array $params): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DSN::from(scheme: 's3', host: 'localhost', params: $params);
    }

    public function testFromParamsReadBack(): void
    {
        $dsn = DSN::from(scheme: 's3', host: 'localhost', params: ['region' => 'eu central', 'url' => 'https://x?y=1&z']);

        $this->assertSame('eu central', $dsn->getParam('region'));
        $this->assertSame('https://x?y=1&z', $dsn->getParam('url'));
    }

    public function testToStringRoundTrips(): void
    {
        $dsn = DSN::from(
            scheme: 'mariadb',
            host: 'localhost',
            user: 'user',
            password: 'p@ss/word',
            port: 3306,
            path: 'data base',
            params: ['charset' => 'utf8'],
        );

        $parsed = new DSN((string) $dsn);

        $this->assertSame('mariadb://user:p%40ss%2Fword@localhost:3306/data%20base?charset=utf8', (string) $dsn);
        $this->assertSame('p@ss/word', $parsed->getPassword());
        $this->assertSame('data base', $parsed->getPath());
        $this->assertSame('utf8', $parsed->getParam('charset'));
    }

    public function testPathIsDecoded(): void
    {
        $dsn = new DSN('s3://localhost/b%3Furl%3Dx');

        $this->assertSame('b?url=x', $dsn->getPath());
        $this->assertSame('', $dsn->getParam('url'));
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
