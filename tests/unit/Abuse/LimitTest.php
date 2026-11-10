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

    public function testTrustedCallersAreExemptFromAThroughputLimit(): void
    {
        $limit = Limit::attempts(['url', 'ip'], 10);

        $this->assertFalse($limit->everyone);
        $this->assertTrue($limit->counts(trusted: false));
        $this->assertFalse($limit->counts(trusted: true));
    }

    public function testALimitForEveryoneCountsTrustedCallersToo(): void
    {
        // A key, or a console caller in admin mode, guessing the code on a verification confirmation route.
        $limit = Limit::attempts(['url', 'param.userId'], 10, everyone: true);

        $this->assertTrue($limit->everyone);
        $this->assertTrue($limit->counts(trusted: false));
        $this->assertTrue($limit->counts(trusted: true));
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
