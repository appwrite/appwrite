<?php

declare(strict_types=1);

namespace Utopia\Lock\Tests\E2E;

use PHPUnit\Framework\AssertionFailedError;
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

    public function testWaitersDoNotRetryInLockstep(): void
    {
        $holder = new Distributed($this->redis, $this->key, 30);
        $this->assertTrue($holder->tryAcquire());

        // Time from starting to wait until the first retry fires. An unjittered
        // ladder sleeps exactly BACKOFF_MIN here every single time, so every
        // waiter in a burst wakes at the same instant and collides again.
        $observed = 0;
        $lowest = PHP_FLOAT_MAX;
        $highest = 0.0;
        for ($run = 0; $run < 25; $run++) {
            $waiter = new Distributed($this->redis, $this->key, 30);
            $start = microtime(true);
            $first = null;
            $waiter->setLogger(function () use ($start, &$first): void {
                $first ??= microtime(true) - $start;
            });
            $waiter->acquire(0.05);
            if ($first !== null) {
                $observed++;
                $lowest = min($lowest, $first);
                $highest = max($highest, $first);
            }
        }

        $holder->release();

        $this->assertGreaterThan(0, $observed, 'No retry was observed; the logger hook never fired');

        // usleep() overshoot on a loaded runner is a couple of milliseconds, so a
        // spread this wide can only come from the wait being randomised.
        $spread = $highest - $lowest;
        $this->assertGreaterThan(
            0.010,
            $spread,
            \sprintf('Retry delays span only %.1fms - waiters are waking in lockstep', $spread * 1000),
        );
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
            $waiter->withLock(fn (): null => null, timeout: 0.2);
        } finally {
            $holder->release();
        }
    }

    public function testWithLockRunsCallbackAndReleases(): void
    {
        $lock = new Distributed($this->redis, $this->key, 30);
        $result = $lock->withLock(fn (): string => 'done', timeout: 1.0);

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

    public function testAdoptedTokenRefreshesLeaseThroughAnotherConnection(): void
    {
        $holder = new Distributed($this->redis, $this->key, 30);
        $this->assertTrue($holder->tryAcquire());
        $token = $holder->token();
        $this->assertNotNull($token);

        $redis = new Redis();
        $this->assertTrue($redis->connect(
            getenv('REDIS_HOST') ?: 'redis',
            (int) (getenv('REDIS_PORT') ?: 6379),
            1.0,
        ));

        $refresher = (new Distributed($redis, $this->key, 30))->adopt($token);

        $this->assertSame($token, $refresher->token());
        $this->assertTrue($refresher->refresh());
        $this->assertTrue($holder->isHeld());

        $holder->release();
        $redis->close();
    }

    public function testAdoptedStaleTokenCannotRefreshOrReleaseSuccessor(): void
    {
        $holder = new Distributed($this->redis, $this->key, 30);
        $this->assertTrue($holder->tryAcquire());
        $token = $holder->token();
        $this->assertNotNull($token);

        $redis = new Redis();
        $this->assertTrue($redis->connect(
            getenv('REDIS_HOST') ?: 'redis',
            (int) (getenv('REDIS_PORT') ?: 6379),
            1.0,
        ));
        $refresher = (new Distributed($redis, $this->key, 30))->adopt($token);

        $this->redis->del($this->key);
        $successor = new Distributed($this->redis, $this->key, 30);
        $this->assertTrue($successor->tryAcquire());

        $this->assertFalse($refresher->refresh());
        $refresher->release();
        $this->assertTrue($successor->isHeld());

        $successor->release();
        $redis->close();
    }

    public function testEmptyTokenCannotBeAdopted(): void
    {
        $lock = new Distributed($this->redis, $this->key, 30);

        try {
            $lock->adopt('');
            $this->fail('An empty token must not grant authority over a distributed lock');
        } catch (\InvalidArgumentException) {
            $this->assertNull($lock->token());
            $this->assertFalse($lock->refresh());
        }
    }

    public function testHeldLockCannotReplaceItsToken(): void
    {
        $lock = new Distributed($this->redis, $this->key, 30);
        $this->assertTrue($lock->tryAcquire());
        $token = $lock->token();

        try {
            $lock->adopt('another-token');
            $this->fail('Replacing a held lock token would abandon its authority to release the lease');
        } catch (\LogicException) {
            $this->assertSame($token, $lock->token());
            $this->assertTrue($lock->isHeld());
        } finally {
            $lock->release();
        }
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


    public function testNegativeTimeoutWaitsUntilRelease(): void
    {
        if (! \function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork required');
        }

        $host = getenv('REDIS_HOST') ?: 'redis';
        $port = (int) (getenv('REDIS_PORT') ?: 6379);

        $ready = tempnam(sys_get_temp_dir(), 'utopia-lock-ready-');
        $started = tempnam(sys_get_temp_dir(), 'utopia-lock-started-');

        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid, 'Failed to fork');

        if ($pid === 0) {
            $redis = new Redis();
            $redis->connect($host, $port, 1.0);
            $holder = new Distributed($redis, $this->key, 30);
            $acquired = $holder->tryAcquire();
            file_put_contents($ready, $acquired ? '1' : '0');

            $waited = 0.0;
            while ($waited < 5.0 && file_get_contents($started) !== '1') {
                usleep(10_000);
                $waited += 0.01;
            }

            if ($acquired) {
                usleep(200_000);
                $holder->release();
            }

            exit(0);
        }

        try {
            $deadline = microtime(true) + 5.0;
            while (microtime(true) < $deadline && file_get_contents($ready) === '') {
                usleep(10_000);
            }

            $this->assertSame('1', file_get_contents($ready), 'Child failed to acquire the lock');

            $waiter = new Distributed($this->redis, $this->key, 30);
            $start = microtime(true);
            file_put_contents($started, '1');
            $this->assertTrue($waiter->acquire(-1.0), 'Negative timeout must wait until the lock is released');
            $elapsed = microtime(true) - $start;
            $this->assertGreaterThanOrEqual(0.15, $elapsed, 'Acquire must have blocked until the holder released');
            $waiter->release();
        } finally {
            file_put_contents($started, '1');
            $this->reapChild($pid);
            @unlink($ready);
            @unlink($started);
        }
    }

    public function testChildAcquisitionFailureTerminatesPromptly(): void
    {
        if (! \function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork required');
        }

        $host = getenv('REDIS_HOST') ?: 'redis';
        $port = (int) (getenv('REDIS_PORT') ?: 6379);

        $holderLock = new Distributed($this->redis, $this->key, 30);
        $this->assertTrue($holderLock->tryAcquire(), 'Setup must hold the lock so the child cannot acquire it');

        $ready = tempnam(sys_get_temp_dir(), 'utopia-lock-ready-');
        $started = tempnam(sys_get_temp_dir(), 'utopia-lock-started-');

        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid, 'Failed to fork');

        if ($pid === 0) {
            $redis = new Redis();
            $redis->connect($host, $port, 1.0);
            $child = new Distributed($redis, $this->key, 30);
            $acquired = $child->tryAcquire();
            file_put_contents($ready, $acquired ? '1' : '0');

            $waited = 0.0;
            while ($waited < 5.0 && file_get_contents($started) !== '1') {
                usleep(10_000);
                $waited += 0.01;
            }

            if ($acquired) {
                $child->release();
            }

            exit(0);
        }

        $start = microtime(true);
        $thrown = null;

        try {
            $deadline = microtime(true) + 5.0;
            while (microtime(true) < $deadline && file_get_contents($ready) === '') {
                usleep(10_000);
            }

            try {
                $this->assertSame('1', file_get_contents($ready), 'Child failed to acquire the lock');
            } catch (AssertionFailedError $exception) {
                $thrown = $exception;
            }
        } finally {
            file_put_contents($started, '1');
            $this->reapChild($pid);
            @unlink($ready);
            @unlink($started);
        }

        $elapsed = microtime(true) - $start;
        $holderLock->release();

        $this->assertInstanceOf(AssertionFailedError::class, $thrown, 'Forced acquisition failure must surface as an assertion failure');
        $this->assertLessThan(5.0, $elapsed, 'Failure path must terminate promptly instead of hanging in waitpid');
    }

    private function reapChild(int $pid): void
    {
        $deadline = microtime(true) + 2.0;

        while (microtime(true) < $deadline) {
            $result = pcntl_waitpid($pid, $status, WNOHANG);

            if ($result === $pid || $result === -1) {
                return;
            }

            usleep(10_000);
        }

        posix_kill($pid, SIGKILL);
        pcntl_waitpid($pid, $status);
    }
}
