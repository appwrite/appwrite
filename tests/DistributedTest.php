<?php

declare(strict_types=1);

namespace Utopia\Lock\Tests;

use PHPUnit\Framework\TestCase;
use Redis;
use Utopia\Lock\Distributed;
use Utopia\Lock\Exception\Contention;

final class DistributedTest extends TestCase
{
    private Redis $redis;

    private string $key = '';

    protected function setUp(): void
    {
        if (! \extension_loaded('redis')) {
            $this->markTestSkipped('ext-redis required');
        }

        $host = getenv('REDIS_HOST') ?: 'redis';
        $port = (int) (getenv('REDIS_PORT') ?: 6379);

        $this->redis = new Redis();
        try {
            $this->redis->connect($host, $port, 1.0);
        } catch (\Throwable $exception) {
            $this->markTestSkipped("Redis not reachable at {$host}:{$port}: {$exception->getMessage()}");
        }

        $this->key = 'utopia-lock-test:' . bin2hex(random_bytes(8));
        $this->redis->del($this->key);
    }

    protected function tearDown(): void
    {
        if (isset($this->redis) && $this->key !== '') {
            $this->redis->del($this->key);
        }
    }

    public function testAcquireAndRelease(): void
    {
        $lock = new Distributed($this->redis, $this->key, 30);

        $this->assertTrue($lock->tryAcquire());
        $this->assertTrue($lock->isHeld());

        $other = new Distributed($this->redis, $this->key, 30);
        $this->assertFalse($other->tryAcquire());

        $lock->release();
        $this->assertFalse($lock->isHeld());

        $this->assertTrue($other->tryAcquire());
        $other->release();
    }

    public function testAcquireRespectsFractionalTimeout(): void
    {
        $holder = new Distributed($this->redis, $this->key, 30);
        $this->assertTrue($holder->tryAcquire());

        $waiter = new Distributed($this->redis, $this->key, 30);
        $start = microtime(true);
        $acquired = $waiter->acquire(0.25);
        $elapsed = microtime(true) - $start;

        $this->assertFalse($acquired);
        $this->assertGreaterThanOrEqual(0.2, $elapsed);
        $this->assertLessThan(1.0, $elapsed, 'Timeout must be measured in fractional seconds, not 10s sleeps');

        $holder->release();
    }

    public function testReleaseDoesNotRemoveForeignLock(): void
    {
        $this->redis->set($this->key, 'other-owner', ['EX' => 30]);

        $lock = new Distributed($this->redis, $this->key, 30);
        $this->assertFalse($lock->tryAcquire());

        $lock->release();

        $this->assertSame('other-owner', $this->redis->get($this->key));
    }

    public function testWithLockThrowsContentionOnTimeout(): void
    {
        $holder = new Distributed($this->redis, $this->key, 30);
        $this->assertTrue($holder->tryAcquire());

        $waiter = new Distributed($this->redis, $this->key, 30);

        try {
            $this->expectException(Contention::class);
            $waiter->withLock(fn(): null => null, timeout: 0.2);
        } finally {
            $holder->release();
        }
    }

    public function testWithLockRunsCallbackAndReleases(): void
    {
        $lock = new Distributed($this->redis, $this->key, 30);
        $result = $lock->withLock(fn(): string => 'done', timeout: 1.0);

        $this->assertSame('done', $result);
        $this->assertLessThanOrEqual(0, $this->redis->exists($this->key));
    }

    public function testRefreshExtendsTtl(): void
    {
        $lock = new Distributed($this->redis, $this->key, 5);
        $this->assertTrue($lock->tryAcquire());
        $this->assertGreaterThan(0, $this->redis->ttl($this->key));
        $this->assertTrue($lock->refresh());
        $lock->release();
    }

    public function testTokenIsTheValueOnTheKeyAndIsClearedOnRelease(): void
    {
        $lock = new Distributed($this->redis, $this->key, 30);

        $this->assertTrue($lock->tryAcquire());
        $token = $lock->token();

        $this->assertSame(
            $this->redis->get($this->key),
            $token,
            'the token must be the value on the key, so a record naming it can be compared against the live holder',
        );

        $lock->release();
        $this->assertNull($lock->token());
    }

    public function testEachAcquisitionMintsItsOwnToken(): void
    {
        $lock = new Distributed($this->redis, $this->key, 30);

        $this->assertTrue($lock->tryAcquire());
        $first = $lock->token();
        $lock->release();

        $this->assertTrue($lock->tryAcquire());
        $second = $lock->token();
        $lock->release();

        $this->assertNotSame(
            $first,
            $second,
            'a token identifies one acquisition, so work recorded under a lapsed lease cannot pass for work under its successor',
        );
    }

    public function testTokenSurvivesTheLossOfTheLease(): void
    {
        $lock = new Distributed($this->redis, $this->key, 30);
        $this->assertTrue($lock->tryAcquire());
        $token = $lock->token();

        // The lease lapses and a successor takes the key, exactly as an expired TTL
        // would leave it.
        $this->redis->del($this->key);
        $successor = new Distributed($this->redis, $this->key, 30);
        $this->assertTrue($successor->tryAcquire());

        $this->assertFalse($lock->isHeld(), 'the lease is gone, and isHeld is what says so');
        $this->assertFalse($lock->refresh(), 'a lapsed lease cannot be extended');
        $this->assertSame(
            $token,
            $lock->token(),
            'the token names the acquisition that did the work, so it must not change when that lease is lost: a record written under it has to stay distinguishable from the successor',
        );
        $this->assertNotSame($lock->token(), $successor->token());

        $successor->release();
    }

    public function testTokenIsNotIssuedForAFailedAcquire(): void
    {
        $holder = new Distributed($this->redis, $this->key, 30);
        $waiter = new Distributed($this->redis, $this->key, 30);

        $this->assertNull($waiter->token(), 'a lock holding no lease has no token to hand out');
        $this->assertTrue($holder->tryAcquire());
        $this->assertFalse($waiter->tryAcquire());

        $this->assertNull($waiter->token(), 'a lock that never took the key must not name itself the holder');
        $this->assertSame($holder->token(), $this->redis->get($this->key));

        $holder->release();
    }

    public function testLoggerReceivesMessages(): void
    {
        $holder = new Distributed($this->redis, $this->key, 30);
        $this->assertTrue($holder->tryAcquire());

        $messages = [];
        $waiter = (new Distributed($this->redis, $this->key, 30))
            ->setLogger(function (string $message) use (&$messages): void {
                $messages[] = $message;
            });

        $waiter->acquire(0.15);

        $this->assertNotEmpty($messages);
        $holder->release();
    }
}
