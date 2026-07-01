<?php

declare(strict_types=1);

namespace Tests\Unit\Locking;

use Appwrite\Extend\Exception;
use Appwrite\Locking\Lock;
use Appwrite\Locking\PlatformDBLock;
use PHPUnit\Framework\TestCase;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
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
        return fn (string $key, int $ttl, \Closure $callback): mixed => $callback(new MemoryLock($key, $this->heldLocks));
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

    public function test_run_uses_short_try_once_lock_window(): void
    {
        $ttl = null;
        $timeout = null;

        $lock = new Lock(
            function (string $key, int $lockTtl, \Closure $callback) use (&$ttl, &$timeout): mixed {
                $ttl = $lockTtl;

                return $callback(new InspectingLock(new MemoryLock($key, $this->heldLocks), function (float $acquireTimeout) use (&$timeout): void {
                    $timeout = $acquireTimeout;
                }));
            },
            new NoTelemetry(),
            $this->log,
            null,
            $this->project,
        );

        $lock->run('keys', 'k1', fn () => null);

        $this->assertSame(5, $ttl);
        $this->assertEqualsWithDelta(0.0, $timeout, PHP_FLOAT_EPSILON);
    }

    public function test_run_skips_on_contention(): void
    {
        $key = self::KEY_PREFIX.'keys:k1';
        $this->heldLocks[$key] = true;

        $called = false;
        $lock = $this->makeLock();

        $lock->run('keys', 'k1', function () use (&$called) {
            $called = true;
        });

        $this->assertFalse($called);
        $this->assertArrayHasKey($key, $this->heldLocks);
    }

    public function test_run_or_fail_uses_short_http_lock_ttl_and_acquire_timeout(): void
    {
        $ttl = null;
        $timeout = null;

        $lock = new Lock(
            function (string $key, int $lockTtl, \Closure $callback) use (&$ttl, &$timeout): mixed {
                $ttl = $lockTtl;

                return $callback(new InspectingLock(new MemoryLock($key, $this->heldLocks), function (float $acquireTimeout) use (&$timeout): void {
                    $timeout = $acquireTimeout;
                }));
            },
            new NoTelemetry(),
            $this->log,
            null,
            $this->project,
        );

        $lock->runOrFail('keys', 'k1', fn () => null);

        $this->assertSame(10, $ttl);
        $this->assertEqualsWithDelta(3.0, $timeout, PHP_FLOAT_EPSILON);
    }

    public function test_best_effort_backend_error_runs_callback_unlocked(): void
    {
        if (! \class_exists(\RedisException::class)) {
            $this->markTestSkipped('Redis extension is required to simulate RedisException.');
        }

        $lock = new Lock(
            fn (string $key, int $ttl, \Closure $callback): mixed => $callback(new ThrowingAcquireLock(new \RedisException('redis unavailable'))),
            new NoTelemetry(),
            $this->log,
            null,
            $this->project,
        );

        $called = false;
        $lock->run('keys', 'k1', function () use (&$called) {
            $called = true;
        });

        $this->assertTrue($called);
    }

    public function test_run_or_fail_backend_error_runs_callback_unlocked(): void
    {
        if (! \class_exists(\RedisException::class)) {
            $this->markTestSkipped('Redis extension is required to simulate RedisException.');
        }

        $lock = new Lock(
            fn (string $key, int $ttl, \Closure $callback): mixed => $callback(new ThrowingAcquireLock(new \RedisException('redis unavailable'))),
            new NoTelemetry(),
            $this->log,
            null,
            $this->project,
        );

        $called = false;
        $lock->runOrFail('keys', 'k1', function () use (&$called) {
            $called = true;
        });

        $this->assertTrue($called);
    }

    public function test_best_effort_pool_checkout_error_runs_callback_unlocked(): void
    {
        if (! \class_exists(\RedisException::class)) {
            $this->markTestSkipped('Redis extension is required to simulate RedisException.');
        }

        $lock = new Lock(
            fn (string $key, int $ttl, \Closure $callback): mixed => throw new \RedisException('pool unavailable'),
            new NoTelemetry(),
            $this->log,
            null,
            $this->project,
        );

        $called = false;
        $lock->run('keys', 'k1', function () use (&$called) {
            $called = true;
        });

        $this->assertTrue($called);
    }

    public function test_callback_redis_exception_is_not_swallowed(): void
    {
        if (! \class_exists(\RedisException::class)) {
            $this->markTestSkipped('Redis extension is required to simulate RedisException.');
        }

        $lock = $this->makeLock();

        $this->expectException(\RedisException::class);
        $lock->run('keys', 'k1', fn () => throw new \RedisException('callback failed'));
    }

    public function test_release_error_after_callback_is_logged_but_not_thrown(): void
    {
        if (! \class_exists(\RedisException::class)) {
            $this->markTestSkipped('Redis extension is required to simulate RedisException.');
        }

        $lock = new Lock(
            fn (string $key, int $ttl, \Closure $callback): mixed => $callback(new ThrowingReleaseLock(new MemoryLock($key, $this->heldLocks), new \RedisException('release failed'))),
            new NoTelemetry(),
            $this->log,
            null,
            $this->project,
        );

        $this->assertSame('ok', $lock->runOrFail('keys', 'k1', fn () => 'ok'));
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

    public function test_try_with_key_skips_on_contention(): void
    {
        $custom = 'lock:test:contended';
        $this->heldLocks[$custom] = true;

        $lock = $this->makeLock();
        $called = false;
        $lock->tryWithKey($custom, function () use (&$called): void {
            $called = true;
        }, ttl: 5);

        $this->assertFalse($called);
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
        $lock->run('keys', 'k1', function () use (&$called) {
            $called = true;
        });

        $this->assertFalse($called, 'Lock without project sequence should hash to the unknown bucket');

        unset($this->heldLocks[$key]);
    }

    public function test_key_for_project_uses_given_project_sequence(): void
    {
        $lock = new Lock(
            $this->withLock(),
            new NoTelemetry(),
            $this->log,
            null,
            new Document(),
        );

        $project = new Document([
            '$id' => 'routed-project',
            '$sequence' => '84',
        ]);

        $this->assertSame(
            'lock:platform:84:projects:routed-project:accessedAt',
            $lock->keyForProject($project, 'projects', 'routed-project', 'accessedAt')
        );
    }

    public function test_platform_db_lock_updates_attribute_under_attribute_key(): void
    {
        $dbForPlatform = $this->createMock(Database::class);
        $dbForPlatform
            ->expects($this->once())
            ->method('updateDocument')
            ->with(
                'projects',
                'p1',
                $this->callback(function (Document $document): bool {
                    $this->assertSame('now', $document->getAttribute('accessedAt'));
                    return true;
                })
            )
            ->willReturn(new Document(['$id' => 'p1']));

        $platformDBLock = new PlatformDBLock($this->makeLock(), $dbForPlatform, new Authorization());

        $this->assertSame(
            'p1',
            $platformDBLock->tryUpdateAttribute('projects', 'p1', 'accessedAt', 'now')?->getId()
        );
        $this->assertArrayNotHasKey(self::KEY_PREFIX.'projects:p1:accessedAt', $this->heldLocks);
    }

    public function test_platform_db_lock_try_run_uses_collection_key(): void
    {
        $dbForPlatform = $this->createStub(Database::class);
        $platformDBLock = new PlatformDBLock($this->makeLock(), $dbForPlatform, new Authorization());

        $called = false;
        $platformDBLock->tryRun('keys', 'k1', function () use (&$called): void {
            $called = true;
        });

        $this->assertTrue($called);
        $this->assertArrayNotHasKey(self::KEY_PREFIX.'keys:k1', $this->heldLocks);
    }

    public function test_platform_db_lock_try_run_skips_on_contention(): void
    {
        $this->heldLocks[self::KEY_PREFIX.'keys:k1'] = true;

        $dbForPlatform = $this->createStub(Database::class);
        $platformDBLock = new PlatformDBLock($this->makeLock(), $dbForPlatform, new Authorization());

        $called = false;
        $platformDBLock->tryRun('keys', 'k1', function () use (&$called): void {
            $called = true;
        });

        $this->assertFalse($called);
    }

    public function test_platform_db_lock_updates_document_under_document_key(): void
    {
        $updates = new Document(['accessedAt' => 'now', 'sdks' => ['php']]);

        $dbForPlatform = $this->createMock(Database::class);
        $dbForPlatform
            ->expects($this->once())
            ->method('updateDocument')
            ->with('keys', 'k1', $updates)
            ->willReturn(new Document(['$id' => 'k1']));

        $platformDBLock = new PlatformDBLock($this->makeLock(), $dbForPlatform, new Authorization());

        $this->assertSame(
            'k1',
            $platformDBLock->tryUpdateDocument('keys', 'k1', $updates)?->getId()
        );
        // Document-level lock has no attribute suffix and is released after the write.
        $this->assertArrayNotHasKey(self::KEY_PREFIX.'keys:k1', $this->heldLocks);
    }

    public function test_platform_db_lock_skips_document_update_on_contention(): void
    {
        $this->heldLocks[self::KEY_PREFIX.'keys:k1'] = true;

        $dbForPlatform = $this->createMock(Database::class);
        $dbForPlatform
            ->expects($this->never())
            ->method('updateDocument');

        $platformDBLock = new PlatformDBLock($this->makeLock(), $dbForPlatform, new Authorization());

        $this->assertNotInstanceOf(\Utopia\Database\Document::class, $platformDBLock->tryUpdateDocument('keys', 'k1', new Document(['accessedAt' => 'now'])));
    }

    public function test_pool_checkout_exception_runs_callback_unlocked(): void
    {
        // Pool::pop() throws a bare \Exception on exhaustion, before the lock is
        // acquired. The wrapper must fail open and run the callback unlocked.
        $lock = new Lock(
            fn (string $key, int $ttl, \Closure $callback): mixed => throw new \Exception('Pool \'lock\' is empty'),
            new NoTelemetry(),
            $this->log,
            null,
            $this->project,
        );

        $called = false;
        $lock->run('keys', 'k1', function () use (&$called) {
            $called = true;
        });

        $this->assertTrue($called);
    }

    public function test_non_pool_exception_is_not_swallowed(): void
    {
        // Only the literal \Exception (pool exhaustion) is treated as fail-open;
        // any subclass thrown while entering the lock must keep propagating.
        $lock = new Lock(
            fn (string $key, int $ttl, \Closure $callback): mixed => throw new \RuntimeException('unexpected'),
            new NoTelemetry(),
            $this->log,
            null,
            $this->project,
        );

        $this->expectException(\RuntimeException::class);
        $lock->run('keys', 'k1', fn () => null);
    }

    public function test_platform_db_lock_skips_routed_project_update_on_contention(): void
    {
        $lock = new Lock(
            $this->withLock(),
            new NoTelemetry(),
            $this->log,
            null,
            new Document(),
        );

        $project = new Document([
            '$id' => 'routed-project',
            '$sequence' => '84',
        ]);

        $this->heldLocks['lock:platform:84:projects:routed-project:accessedAt'] = true;

        $dbForPlatform = $this->createMock(Database::class);
        $dbForPlatform
            ->expects($this->never())
            ->method('updateDocument');

        $platformDBLock = new PlatformDBLock($lock, $dbForPlatform, new Authorization());

        $this->assertNotInstanceOf(\Utopia\Database\Document::class, $platformDBLock->tryUpdateAttribute(
            'projects',
            'routed-project',
            'accessedAt',
            'now',
            $project
        ));
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

final class InspectingLock implements UtopiaLock
{
    public function __construct(
        private readonly UtopiaLock $lock,
        private readonly \Closure $inspectTimeout,
    ) {
    }

    public function acquire(float $timeout = 0.0): bool
    {
        ($this->inspectTimeout)($timeout);

        return $this->lock->acquire($timeout);
    }

    public function tryAcquire(): bool
    {
        return $this->lock->tryAcquire();
    }

    public function release(): void
    {
        $this->lock->release();
    }

    public function withLock(callable $callback, float $timeout = 0.0): mixed
    {
        if (! $this->acquire($timeout)) {
            throw new LockContention('Failed to acquire distributed lock');
        }

        try {
            return $callback();
        } finally {
            $this->release();
        }
    }
}

final class ThrowingAcquireLock implements UtopiaLock
{
    public function __construct(private readonly \Throwable $throwable)
    {
    }

    public function acquire(float $timeout = 0.0): bool
    {
        throw $this->throwable;
    }

    public function tryAcquire(): bool
    {
        throw $this->throwable;
    }

    public function release(): void
    {
    }

    public function withLock(callable $callback, float $timeout = 0.0): mixed
    {
        throw $this->throwable;
    }
}

final class ThrowingReleaseLock implements UtopiaLock
{
    public function __construct(
        private readonly UtopiaLock $lock,
        private readonly \Throwable $throwable,
    ) {
    }

    public function acquire(float $timeout = 0.0): bool
    {
        return $this->lock->acquire($timeout);
    }

    public function tryAcquire(): bool
    {
        return $this->lock->tryAcquire();
    }

    public function release(): void
    {
        $this->lock->release();

        throw $this->throwable;
    }

    public function withLock(callable $callback, float $timeout = 0.0): mixed
    {
        if (! $this->acquire($timeout)) {
            throw new LockContention('Failed to acquire distributed lock');
        }

        try {
            return $callback();
        } finally {
            $this->release();
        }
    }
}
