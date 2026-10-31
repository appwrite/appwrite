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
    public function testNamespacedViewsShareExistingDataVersionsAndPurges(bool $multiplexed): void
    {
        $error = null;
        run(function () use ($multiplexed, &$error): void {
            $redis = new Redis();
            $port = (int) (\getenv('CACHE_VERSIONED_TEST_PORT') ?: Services::REDIS_PORT);
            $redis->connect(Services::HOST, $port);
            $adapter = $multiplexed ? new Multiplexing(Services::HOST, $port) : new RedisAdapter($redis);
            $existing = new Cache($adapter);
            $cache = $existing->withNamespace('v2:');
            $key = 'namespace:'.\bin2hex(\random_bytes(8));
            try {
                $existing->save('v2:'.$key, 'warm');
                $this->assertSame('warm', $cache->load($key, 60));
                $this->assertSame($existing->loadVersioned('v2:'.$key), $cache->loadVersioned($key));
                $lease = $existing->getLease('v2:'.$key);
                $cache->touch($key);
                $this->assertFalse($existing->saveWithLease('v2:'.$key, 'stale', '', $lease));

                $existing->saveMany('v2:'.$key, ['documents' => ['row'], 'total' => '1']);
                $this->assertSame(['documents' => ['row'], 'total' => '1'], $cache->loadMany($key, 60, ['documents', 'total']));
                $cache->saveMany($key, ['total' => '2']);
                $this->assertSame('2', $existing->load('v2:'.$key, 60, 'total'));
                $this->assertContains('documents', $cache->list($key));
                $recorded = $cache->recordPurges(fn (): bool => $cache->purge($key, 'documents'));
                $this->assertSame([$key => ['documents' => true]], $recorded);
                $this->assertFalse($existing->load('v2:'.$key, 60, 'documents'));
                $this->assertSame('2', $cache->load($key, 60, 'total'));

                $snapshot = $existing->loadVersioned('v2:'.$key);
                $version = $cache->compareAndSwap($key, ['owners' => []], $snapshot['version']);
                $this->assertIsString($version);
                $this->assertSame(['version' => $version, 'value' => ['owners' => []]], $existing->loadVersioned('v2:'.$key));
            } catch (Throwable $caught) {
                $error = $caught;
            } finally {
                if ($adapter instanceof Multiplexing) {
                    $adapter->disconnect();
                }
                $redis->del('v2:'.$key);
                $redis->close();
            }
        });
        if ($error instanceof Throwable) {
            throw $error;
        }
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
                $redis->del($key); // Physical eviction removes the version and tombstone together.
                $fresh = $cache->getLease($key);
                $this->assertFalse($cache->saveWithLease($key, 'stale', '', $lease));
                $this->assertNotSame($lease, $fresh);
                $this->assertSame('fresh', $cache->saveWithLease($key, 'fresh', '', $fresh));
                $this->assertSame('fresh', $cache->load($key, 60));

                $snapshot = $cache->loadVersioned($key);
                $committed = $cache->compareAndSwap($key, 'next', $snapshot['version']);
                $this->assertNotFalse($committed);
                $this->assertSame(['version' => $committed, 'value' => 'next'], $cache->loadVersioned($key));
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
