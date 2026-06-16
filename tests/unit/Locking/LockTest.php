<?php

declare(strict_types=1);

namespace Tests\Unit\Locking;

use Appwrite\Extend\Exception;
use Appwrite\Locking\Lock;
use PHPUnit\Framework\TestCase;
use Utopia\Config\Config;
use Utopia\Database\Document;
use Utopia\Lock\Exception\Contention as LockContention;
use Utopia\Lock\Lock as UtopiaLock;
use Utopia\Logger\Log;
use Utopia\Telemetry\Adapter\None as NoTelemetry;

final class LockTest extends TestCase
{
    /**
     * @var array<string, bool>
     */
    private array $heldLocks = [];

    private Document $project;

    private Log $log;

    /**
     * Project sequence used for every test; lock keys are scoped under it
     * so cleanup is bounded.
     */
    private const PROJECT_SEQUENCE = '42';

    private const KEY_PREFIX = 'lock:platform:'.self::PROJECT_SEQUENCE.':';

    protected function setUp(): void
    {
        Config::setParam('errors', require __DIR__.'/../../../app/config/errors.php');

        $this->heldLocks = [];
        $this->project = new Document([
            '$id' => 'test-project',
            '$sequence' => self::PROJECT_SEQUENCE,
        ]);
        $this->log = new Log();
    }

    private function makeLock(): Lock
    {
        return new Lock(
            $this->withLock(),
            new NoTelemetry(),
            $this->log,
            null,
            $this->project,
        );
    }

    private function withLock(): \Closure
    {
        return fn (string $key, int $ttl, \Closure $callback, float $timeout): mixed => (new MemoryLock($key, $this->heldLocks))->withLock($callback, $timeout);
    }

    public function test_run_uses_per_document_key_and_invokes_callback(): void
    {
        $called = false;
        $lock = $this->makeLock();
        $lock->run('keys', 'k1', function () use (&$called) {
            $called = true;
        });

        $this->assertTrue($called);
        $this->assertArrayNotHasKey(self::KEY_PREFIX.'keys:k1', $this->heldLocks);
    }

    public function test_run_throws_on_contention(): void
    {
        $key = self::KEY_PREFIX.'keys:k1';
        $this->heldLocks[$key] = true;

        $called = false;
        $lock = $this->makeLock();

        try {
            $lock->run('keys', 'k1', function () use (&$called) {
                $called = true;
            });
            $this->fail('Expected lock contention to throw.');
        } catch (Exception $e) {
            $this->assertSame(Exception::GENERAL_RESOURCE_LOCKED, $e->getType());
            $this->assertFalse($called);
            $this->assertArrayHasKey($key, $this->heldLocks);
        }
    }

    public function test_backend_error_does_not_run_callback_unlocked(): void
    {
        if (! \class_exists(\RedisException::class)) {
            $this->markTestSkipped('Redis extension is required to simulate RedisException.');
        }

        $lock = new Lock(
            fn (string $key, int $ttl, \Closure $callback, float $timeout): mixed => throw new \RedisException('redis unavailable'),
            new NoTelemetry(),
            $this->log,
            null,
            $this->project,
        );

        $called = false;
        try {
            $lock->run('keys', 'k1', function () use (&$called) {
                $called = true;
            });
            $this->fail('Expected Redis backend errors to bubble.');
        } catch (\RedisException) {
            $this->assertFalse($called);
        }
    }

    public function test_pool_checkout_error_does_not_run_callback_unlocked(): void
    {
        if (! \class_exists(\RedisException::class)) {
            $this->markTestSkipped('Redis extension is required to simulate RedisException.');
        }

        $lock = new Lock(
            fn (string $key, int $ttl, \Closure $callback, float $timeout): mixed => throw new \RedisException('redis unavailable'),
            new NoTelemetry(),
            $this->log,
            null,
            $this->project,
        );

        $called = false;
        try {
            $lock->run('keys', 'k1', function () use (&$called) {
                $called = true;
            });
            $this->fail('Expected Redis backend errors to bubble.');
        } catch (\RedisException) {
            $this->assertFalse($called);
        }
    }

    public function test_run_or_fail_throws_on_contention(): void
    {
        $key = self::KEY_PREFIX.'projects:p1';
        $this->heldLocks[$key] = true;

        $lock = $this->makeLock();

        $this->expectException(Exception::class);
        try {
            $lock->runOrFail('projects', 'p1', fn () => 'never-runs');
        } catch (Exception $e) {
            $this->assertSame(Exception::GENERAL_RESOURCE_LOCKED, $e->getType());
            throw $e;
        }
    }

    public function test_run_or_fail_returns_callback_value_when_uncontended(): void
    {
        $lock = $this->makeLock();
        $result = $lock->runOrFail('projects', 'p1', fn () => 'ok');

        $this->assertSame('ok', $result);
        $this->assertArrayNotHasKey(self::KEY_PREFIX.'projects:p1', $this->heldLocks);
    }

    public function test_with_key_uses_raw_key(): void
    {
        $custom = 'lock:test:custom-key';
        $called = false;
        $lock = $this->makeLock();
        $lock->withKey($custom, function () use (&$called) {
            $called = true;
        });

        $this->assertTrue($called);
        $this->assertArrayNotHasKey($custom, $this->heldLocks);
    }

    public function test_with_key_throws_on_contention(): void
    {
        $custom = 'lock:test:contended';
        $this->heldLocks[$custom] = true;

        $lock = $this->makeLock();
        $this->expectException(Exception::class);
        $lock->withKey($custom, fn () => null, ttl: 5, waitTimeout: 0.1);
    }

    public function test_disabled_mode_runs_callback_unlocked(): void
    {
        $previous = \getenv('_APP_LOCKING_ENABLED');
        \putenv('_APP_LOCKING_ENABLED=disabled');
        try {
            $key = self::KEY_PREFIX.'keys:k1';
            $this->heldLocks[$key] = true;

            $called = false;
            $lock = $this->makeLock();
            $lock->run('keys', 'k1', function () use (&$called) {
                $called = true;
            });

            $this->assertTrue($called);
            $this->assertArrayHasKey($key, $this->heldLocks);
        } finally {
            $previous === false ? \putenv('_APP_LOCKING_ENABLED') : \putenv('_APP_LOCKING_ENABLED='.$previous);
        }
    }

    public function test_project_without_sequence_falls_back_to_unknown(): void
    {
        $emptyProject = new Document();
        $lock = new Lock(
            $this->withLock(),
            new NoTelemetry(),
            $this->log,
            null,
            $emptyProject,
        );

        $key = 'lock:platform:unknown:keys:k1';
        $this->heldLocks[$key] = true;

        $called = false;
        try {
            $lock->run('keys', 'k1', function () use (&$called) {
                $called = true;
            });
            $this->fail('Expected unknown-bucket contention to throw.');
        } catch (Exception $e) {
            $this->assertSame(Exception::GENERAL_RESOURCE_LOCKED, $e->getType());
            $this->assertFalse($called, 'Lock without project sequence should hash to the unknown bucket');
        }

        unset($this->heldLocks[$key]);
    }
}

final class MemoryLock implements UtopiaLock
{
    private bool $acquired = false;

    /**
     * @param array<string, bool> $heldLocks
     */
    public function __construct(
        private readonly string $key,
        private array &$heldLocks,
    ) {
    }

    public function acquire(float $timeout = 0.0): bool
    {
        return $this->tryAcquire();
    }

    public function tryAcquire(): bool
    {
        if (isset($this->heldLocks[$this->key])) {
            return false;
        }

        $this->heldLocks[$this->key] = true;
        $this->acquired = true;

        return true;
    }

    public function release(): void
    {
        if (! $this->acquired) {
            return;
        }

        unset($this->heldLocks[$this->key]);
        $this->acquired = false;
    }

    public function withLock(callable $callback, float $timeout = 0.0): mixed
    {
        if (! $this->acquire($timeout)) {
            throw new LockContention("Failed to acquire distributed lock: {$this->key}");
        }

        try {
            return $callback();
        } finally {
            $this->release();
        }
    }
}
