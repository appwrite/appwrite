<?php

declare(strict_types=1);

namespace Utopia\Cache\Tests\E2E;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Redis;
use Throwable;
use Utopia\Cache\Adapter\Redis as RedisAdapter;
use Utopia\Cache\Adapter\Redis\Multiplexing;
use Utopia\Cache\Cache;
use Utopia\Cache\Tests\Services;

use function Swoole\Coroutine\run;

final class VersionedTest extends TestCase
{
    /** @return \Iterator<string, array{bool}> */
    public static function transports(): \Iterator
    {
        yield 'redis' => [false];
        yield 'multiplexing' => [true];
    }

    #[DataProvider('transports')]
    public function testEvictionRejectsAnOldLeaseAndAllowsAFreshFill(bool $multiplexed): void
    {
        $error = null;
        run(function () use ($multiplexed, &$error): void {
            $redis = new Redis();
            $port = (int) (\getenv('CACHE_VERSIONED_TEST_PORT') ?: Services::REDIS_PORT);
            $redis->connect(Services::HOST, $port);
            $adapter = $multiplexed ? new Multiplexing(Services::HOST, $port) : new RedisAdapter($redis);
            $cache = new Cache($adapter);
            $key = 'versioned:'.\bin2hex(\random_bytes(8));
            try {
                $lease = $cache->getLease($key);
                $cache->purge($key);
                $redis->del($key); // Physical eviction, including generations/tombstones.
                $this->assertFalse($cache->saveWithLease($key, 'stale', '', $lease));
                $fresh = $cache->getLease($key);
                $this->assertNotSame($lease, $fresh);
                $this->assertSame('fresh', $cache->saveWithLease($key, 'fresh', '', $fresh));
                $this->assertSame('fresh', $cache->load($key, 60));

                $snapshot = $cache->loadVersioned($key);
                $this->assertTrue($cache->compareAndSwap($key, 'next', $snapshot['version']));
                $this->assertFalse($cache->compareAndSwap($key, 'lost update', $snapshot['version']));
                $this->assertSame('next', $cache->load($key, 60));
                $snapshot = $cache->loadVersioned($key);
                $cache->save($key, 'ordinary write');
                $this->assertFalse($cache->compareAndSwap($key, 'stale snapshot', $snapshot['version']));
                $this->assertSame('ordinary write', $cache->load($key, 60));
                $this->assertSame([$key], $cache->list($key), 'Internal version fields are not cache values');
            } catch (Throwable $caught) {
                $error = $caught;
            } finally {
                if ($adapter instanceof Multiplexing) {
                    $adapter->disconnect();
                }
                $redis->del($key);
                $redis->close();
            }
        });
        if ($error instanceof \Throwable) {
            throw $error;
        }
    }
}
