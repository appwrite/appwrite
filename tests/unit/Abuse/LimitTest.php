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
    public function testAttemptsCountEveryRequest(): void
    {
        $limit = Limit::attempts(['ip', 'url'], 60, 60);

        $this->assertFalse($limit->failures);
        $this->assertSame(60, $limit->seconds);
    }

    public function testFailuresCountOnlyFailedRequests(): void
    {
        $limit = Limit::failures(['projectId', 'url', 'ip'], 100);

        $this->assertTrue($limit->failures);
        $this->assertSame(3600, $limit->seconds);
    }

    /**
     * @return \Iterator<string, array{list<string>, int, int}>
     */
    public static function invalid(): \Iterator
    {
        yield 'no parts' => [[], 10, 60];
        yield 'no limit' => [['ip'], 0, 60];
        yield 'no window' => [['ip'], 10, 0];
        // Each of these would key every request the same, one count shared by every caller.
        yield 'unknown part' => [['project.$id'], 10, 60];
        yield 'placeholder syntax' => [['{ip}'], 10, 60];
        yield 'unnamed param' => [['param.'], 10, 60];
        // One abuse store serves the whole instance, and ids and addresses repeat across projects, so these
        // would let a caller on one project spend the quota of an account or an address on every other project.
        yield 'account without the project' => [['url', 'userId'], 10, 60];
        yield 'address without the project' => [['url', 'param.email'], 10, 60];
        yield 'account param without the project' => [['param.userId'], 10, 60];
    }

    /**
     * @param list<string> $by
     */
    #[DataProvider('invalid')]
    public function testInvalidLimitIsRefused(array $by, int $limit, int $seconds): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Limit::failures($by, $limit, $seconds);
    }

    /**
     * @return \Iterator<string, array{list<string>}>
     */
    public static function callerScoped(): \Iterator
    {
        yield 'per project' => [['projectId', 'url', 'param.userId']];
        // A count also kept per ip only ever counts one caller's own requests, so no project can spend another's.
        yield 'per ip' => [['url', 'ip', 'param.userId']];
        yield 'per ip and account' => [['ip', 'method', 'url', 'userId']];
    }

    /**
     * @param list<string> $by
     */
    #[DataProvider('callerScoped')]
    public function testAccountLimitIsKeptPerProjectOrPerIp(array $by): void
    {
        $this->assertSame($by, Limit::failures($by, 10)->by);
    }

    public function testKeyFromTheRequestsValues(): void
    {
        $swoole = new SwooleRequest();
        $swoole->server = ['remote_addr' => '203.0.113.10', 'request_method' => 'POST'];
        $swoole->post = ['email' => 'Someone@Example.com', 'userId' => 'from-body'];
        $route = new Route('POST', '/v1/account/sessions/email');

        $values = Limit::values($route, new Request($swoole), new Document(['$id' => 'project']), new Document(['$id' => 'user']), ['userId' => 'from-path']);

        // Emails match case-insensitively, path params win, and a part the request lacks is empty.
        $this->assertSame(
            'projectId:project,url:/v1/account/sessions/email,param.email:someone@example.com,param.userId:from-path,ip:203.0.113.10,param.phone:',
            Limit::failures(['projectId', 'url', 'param.email', 'param.userId', 'ip', 'param.phone'], 10)->key($values),
        );
    }
}
