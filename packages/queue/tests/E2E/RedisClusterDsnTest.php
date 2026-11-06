<?php

declare(strict_types=1);

namespace Utopia\Queue\Tests\E2E;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\DSN\DSN;
use Utopia\Queue\Connection\RedisCluster;

/**
 * Runs against the `redis-cluster` compose service: three masters on ports
 * 17000-17002, with no password.
 */
final class RedisClusterDsnTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function dsns(): iterable
    {
        yield 'seeds with ports' => ['redis-cluster://[127.0.0.1:17000;127.0.0.1:17001;127.0.0.1:17002]'];
        yield 'one port for every seed' => ['redis://[127.0.0.1;127.0.0.1]:17001'];
    }

    #[DataProvider('dsns')]
    public function testDsnReachesTheCluster(string $dsn): void
    {
        $connection = RedisCluster::fromDSN(new DSN($dsn));
        $key = '{utopia-queue}.dsn-test-' . uniqid();

        $this->assertTrue($connection->rightPush($key, 'payload'));
        $this->assertSame('payload', $connection->rightPop($key, 1));
    }

    public function testDsnCredentialsReachTheCluster(): void
    {
        // The cluster has no password, so Redis refuses the AUTH: proof the
        // DSN's password was sent.
        $connection = RedisCluster::fromDSN(new DSN('redis-cluster://:secretpw@[127.0.0.1:17000]'));

        $this->expectException(\RedisClusterException::class);
        $connection->rightPush('{utopia-queue}.dsn-test-' . uniqid(), 'payload');
    }

    public function testDsnWithoutSeedsIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RedisCluster::fromDSN(new DSN('redis-cluster://[;]'));
    }
}
