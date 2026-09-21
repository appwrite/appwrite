<?php

declare(strict_types=1);

namespace Tests\E2E\Adapter;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Queue\Broker\Redis as Broker;
use Utopia\Queue\Connection;
use Utopia\Queue\Connection\Redis;
use Utopia\Queue\Connection\RedisCluster;
use Utopia\Queue\Queue;

final class RedisExpiryTest extends TestCase
{
    /** @return \Iterator<string, array{bool}> */
    public static function connections(): \Iterator
    {
        yield 'standalone' => [false];
        yield 'cluster' => [true];
    }

    #[DataProvider('connections')]
    public function testMissingAndExpiredKeysReturnNullAndClaimsAreRecovered(bool $cluster): void
    {
        // Accelerate expiring writes, leaving non-expiring job payloads intact.
        // All reads, expiry, and recovery still use real Redis connections.
        $connection = $this->connection($cluster);
        $namespace = 'expiry-' . uniqid();
        $key = $namespace . '.value';
        $this->assertNull($connection->get($key));
        $connection->set($key, '');
        $this->assertSame('', $connection->get($key));
        $connection->set($key, 'temporary');
        $this->assertSame('temporary', $connection->get($key));
        $connection->set($key, 'temporary', 1);

        $queue = new Queue('jobs', $namespace);
        $broker = new Broker($connection, $connection, reapAfter: 0);
        $broker->publish($queue, ['n' => 1]);
        $this->assertCount(1, $broker->receive($queue, 1));

        sleep(2);
        $this->assertNull($connection->get($key));
        $broker->maintain();
        $messages = $broker->receive($queue, 1);
        $this->assertCount(1, $messages);
        $this->assertSame(['n' => 1], $messages[0]->getPayload());
        $this->assertSame(1, $messages[0]->getAttempts());
        $broker->commit($queue, $messages[0]);
        $connection->close();
    }

    private function connection(bool $cluster): Connection
    {
        return $cluster
            ? new class (['127.0.0.1:17000', '127.0.0.1:17001', '127.0.0.1:17002']) extends RedisCluster {
                use ShortExpiry;
            }
        : new class ('127.0.0.1', 16379) extends Redis {
            use ShortExpiry;
        };
    }
}

trait ShortExpiry
{
    public function set(string $key, string $value, int $ttl = 0): bool
    {
        return parent::set($key, $value, $ttl > 0 ? 1 : 0);
    }
}
