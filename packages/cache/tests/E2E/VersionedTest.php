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
    public function testLegacyRegistrationIgnoresFillGraceButConditionalFillsRespectIt(bool $multiplexed): void
    {
        $error = null;
        run(function () use ($multiplexed, &$error): void {
            $redis = new Redis();
            $port = (int) (\getenv('CACHE_VERSIONED_TEST_PORT') ?: Services::REDIS_PORT);
            $redis->connect(Services::HOST, $port);
            $adapter = $multiplexed ? new Multiplexing(Services::HOST, $port) : new RedisAdapter($redis);
            $adapter->setLeaseGraceWindow(1000);
            $legacy = new Cache($adapter)->legacy();
            $key = 'legacy-grace:'.\bin2hex(\random_bytes(8));
            try {
                $this->assertSame('first', $legacy->save($key, 'first', 'first'));
                $this->assertTrue($legacy->purge($key, 'first'));
                $this->assertSame('second', $legacy->save($key, 'second', 'second'), 'A normal registration must not be refused by fill grace');
                $this->assertFalse($legacy->saveWithLease($key, 'fill', '', $legacy->getLease($key)), 'Conditional fills still respect grace');
                $this->assertSame('second', $legacy->load($key, 60, 'second'));
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
        if ($error instanceof Throwable) {
            throw $error;
        }
    }

    #[DataProvider('transports')]
    public function testLegacyFacadePreservesTheReleasedWireFormat(bool $multiplexed): void
    {
        $error = null;
        run(function () use ($multiplexed, &$error): void {
            $redis = new Redis();
            $port = (int) (\getenv('CACHE_VERSIONED_TEST_PORT') ?: Services::REDIS_PORT);
            $redis->connect(Services::HOST, $port);
            $adapter = $multiplexed ? new Multiplexing(Services::HOST, $port) : new RedisAdapter($redis);
            $cache = new Cache(new \Utopia\Cache\Adapter\CircuitBreaker($adapter, new \Utopia\CircuitBreaker\CircuitBreaker()));
            $legacy = $cache->legacy();
            $key = 'legacy:'.\bin2hex(\random_bytes(8));
            try {
                $this->assertSame('0', $legacy->getLease($key));
                $this->assertSame('owner', $legacy->save($key, 'owner', 'owner'));
                $this->assertSame(['owner'], $redis->hKeys($key), 'Old clients must never enumerate opaque version fields as owners');
                $lease = $legacy->getLease($key);
                $this->assertTrue($legacy->purge($key, 'owner'));
                $this->assertSame('1', $legacy->getLease($key));
                $this->assertFalse($legacy->saveWithLease($key, 'stale', '', $lease));
                $this->assertSame('fresh', $legacy->saveWithLease($key, 'fresh', '', $legacy->getLease($key)));
                $this->assertSame('fresh', $legacy->load($key, 60));
                $this->assertSame([$key], $legacy->list($key));
                $this->assertTrue($legacy->purge($key));
                $this->assertSame('2', $legacy->getLease($key));
                $this->assertSame([], $legacy->list($key));
                $this->assertFalse($redis->hExists($key, '__utopia_version__'));
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
