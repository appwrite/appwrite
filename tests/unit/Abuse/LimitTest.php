<?php

declare(strict_types=1);

namespace Tests\Unit\Abuse;

use Appwrite\Abuse\Limit;
use Appwrite\Utopia\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swoole\Http\Request as SwooleRequest;
use Utopia\Database\Document;
use Utopia\Http\Route;

final class LimitTest extends TestCase
{
    public function testResetOnAKeyNamingTheAccount(): void
    {
        $limit = Limit::attempts('projectId:{projectId},url:{url},email:{param-email}', 10, resetOn: [201]);

        $this->assertSame([201], $limit->resetOn);
        $this->assertFalse($limit->failures);
    }

    /**
     * A success proves one account's secret; on a key naming no account, any success would clear the count.
     */
    public function testResetOnAKeyNamingNoAccountIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Limit::attempts('projectId:{projectId},url:{url},ip:{ip}', 10, resetOn: [201]);
    }

    public function testFailuresAreNeverReset(): void
    {
        $limit = Limit::failures('projectId:{projectId},url:{url},ip:{ip}', 100);

        $this->assertTrue($limit->failures);
        $this->assertSame([], $limit->resetOn);
        $this->assertSame(3600, $limit->seconds);
    }

    /**
     * @return \Iterator<string, array{string, int, int}>
     */
    public static function invalid(): \Iterator
    {
        yield 'no key' => ['', 10, 60];
        yield 'no limit' => ['ip:{ip}', 0, 60];
        yield 'no window' => ['ip:{ip}', 10, 0];
    }

    #[DataProvider('invalid')]
    public function testIncompleteLimitIsRefused(string $key, int $limit, int $seconds): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Limit::failures($key, $limit, $seconds);
    }

    public function testParamsMatchEmailsCaseInsensitivelyAndKeepPathParams(): void
    {
        $swoole = new SwooleRequest();
        $swoole->server = ['remote_addr' => '203.0.113.10', 'request_method' => 'POST'];
        $swoole->post = ['email' => 'Someone@Example.com', 'userId' => 'from-body'];
        $route = new Route('POST', '/v1/account/sessions/email');

        $params = Limit::params($route, new Request($swoole), new Document(['$id' => 'project']), new Document(['$id' => 'user']), ['userId' => 'from-path']);

        $this->assertSame('someone@example.com', $params['{param-email}']);
        $this->assertSame('from-path', $params['{param-userId}']);
        $this->assertSame('203.0.113.10', $params['{ip}']);
        $this->assertSame('project', $params['{projectId}']);
        $this->assertSame('/v1/account/sessions/email', $params['{url}']);
    }
}
