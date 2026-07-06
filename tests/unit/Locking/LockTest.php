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
use Utopia\Logger\Adapter as LoggerAdapter;
use Utopia\Logger\Log;
use Utopia\Logger\Logger;
use Utopia\Telemetry\Adapter\None as NoTelemetry;

if (! \class_exists(\RedisException::class)) {
    \class_alias(LockingRedisException::class, 'RedisException');
}

if (! \defined('APP_VERSION_STABLE')) {
    \define('APP_VERSION_STABLE', 'test');
}

final class LockTest extends TestCase
{
    /**
     * @var array<string, bool>
     */
    private array $heldLocks = [];

    private Document $project;

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
    }

    private function makeLock(): Lock
    {
        return new Lock(
            $this->withLock(),
            new NoTelemetry(),
            null,
            $this->project,
        );
    }

    private function withLock(): \Closure
    {
        return fn (string $key, int $ttl, \Closure $callback): mixed => $callback(new MemoryLock($key, $this->heldLocks));
    }

    public function testTryWithKeyUsesGivenKeyAndInvokesCallback(): void
    {
        $called = false;
        $lock = $this->makeLock();
        $lock->tryWithKey(self::KEY_PREFIX.'keys:k1', function () use (&$called) {
            $called = true;
        }, target: 'keys');

        $this->assertTrue($called);
        $this->assertArrayNotHasKey(self::KEY_PREFIX.'keys:k1', $this->heldLocks);
    }

    public function testTryWithKeyUsesShortTryOnceLockWindow(): void
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
            null,
            $this->project,
        );

        $lock->tryWithKey(self::KEY_PREFIX.'keys:k1', fn () => null, target: 'keys');

        $this->assertSame(5, $ttl);
        $this->assertEqualsWithDelta(0.0, $timeout, PHP_FLOAT_EPSILON);
    }

    public function testTryWithKeySkipsOnContention(): void
    {
        $key = self::KEY_PREFIX.'keys:k1';
        $this->heldLocks[$key] = true;

        $called = false;
        $lock = $this->makeLock();

        $lock->tryWithKey($key, function () use (&$called) {
            $called = true;
        }, target: 'keys');

        $this->assertFalse($called);
        $this->assertArrayHasKey($key, $this->heldLocks);
    }

    public function testWithKeyUsesShortHttpLockTtlAndAcquireTimeout(): void
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
            null,
            $this->project,
        );

        $lock->withKey(self::KEY_PREFIX.'keys:k1', fn () => null, target: 'keys');

        $this->assertSame(10, $ttl);
        $this->assertEqualsWithDelta(3.0, $timeout, PHP_FLOAT_EPSILON);
    }

    public function testBestEffortBackendErrorRunsCallbackUnlocked(): void
    {
        $lock = new Lock(
            fn (string $key, int $ttl, \Closure $callback): mixed => $callback(new ThrowingAcquireLock(new \RedisException('redis unavailable'))),
            new NoTelemetry(),
            null,
            $this->project,
        );

        $called = false;
        $lock->tryWithKey(self::KEY_PREFIX.'keys:k1', function () use (&$called) {
            $called = true;
        }, target: 'keys');

        $this->assertTrue($called);
    }

    public function testBackendErrorReportDoesNotMutateRequestLog(): void
    {
        $requestLog = new Log();
        $adapter = new RecordingLoggerAdapter();
        $logger = new Logger($adapter);

        $lock = new Lock(
            fn (string $key, int $ttl, \Closure $callback): mixed => $callback(new ThrowingAcquireLock(new \RedisException('redis unavailable'))),
            new NoTelemetry(),
            $logger,
            $this->project,
        );

        $lock->tryWithKey(self::KEY_PREFIX.'keys:k1', fn () => null, target: 'keys');

        $this->assertCount(1, $adapter->logs);
        $this->assertNotSame($requestLog, $adapter->logs[0]);
        $this->assertSame([], $requestLog->getTags());
        $this->assertSame([], $requestLog->getExtra());
    }

    public function testWithKeyBackendErrorRunsCallbackUnlocked(): void
    {
        $lock = new Lock(
            fn (string $key, int $ttl, \Closure $callback): mixed => $callback(new ThrowingAcquireLock(new \RedisException('redis unavailable'))),
            new NoTelemetry(),
            null,
            $this->project,
        );

        $called = false;
        $lock->withKey(self::KEY_PREFIX.'keys:k1', function () use (&$called) {
            $called = true;
        }, target: 'keys');

        $this->assertTrue($called);
    }

    public function testBestEffortPoolCheckoutErrorRunsCallbackUnlocked(): void
    {
        $lock = new Lock(
            fn (string $key, int $ttl, \Closure $callback): mixed => throw new \RedisException('pool unavailable'),
            new NoTelemetry(),
            null,
            $this->project,
        );

        $called = false;
        $lock->tryWithKey(self::KEY_PREFIX.'keys:k1', function () use (&$called) {
            $called = true;
        }, target: 'keys');

        $this->assertTrue($called);
    }

    public function testRedisExceptionThrownByCallbackIsNotTreatedAsBackendError(): void
    {
        $lock = $this->makeLock();

        $this->expectException(\RedisException::class);
        $lock->tryWithKey(self::KEY_PREFIX.'keys:k1', fn () => throw new \RedisException('callback failed'), target: 'keys');
    }

    public function testReleaseErrorAfterCallbackIsLoggedButNotThrown(): void
    {
        $lock = new Lock(
            fn (string $key, int $ttl, \Closure $callback): mixed => $callback(new ThrowingReleaseLock(new MemoryLock($key, $this->heldLocks), new \RedisException('release failed'))),
            new NoTelemetry(),
            null,
            $this->project,
        );

        $this->assertSame('ok', $lock->withKey(self::KEY_PREFIX.'keys:k1', fn () => 'ok', target: 'keys'));
    }

    public function testWithKeyThrowsOnContention(): void
    {
        $key = self::KEY_PREFIX.'projects:p1';
        $this->heldLocks[$key] = true;

        $lock = $this->makeLock();

        $this->expectException(Exception::class);
        try {
            $lock->withKey($key, fn () => 'never-runs', target: 'projects');
        } catch (Exception $e) {
            $this->assertSame(Exception::GENERAL_RESOURCE_LOCKED, $e->getType());
            throw $e;
        }
    }

    public function testWithKeyReturnsCallbackValueWhenUncontended(): void
    {
        $lock = $this->makeLock();
        $result = $lock->withKey(self::KEY_PREFIX.'projects:p1', fn () => 'ok', target: 'projects');

        $this->assertSame('ok', $result);
        $this->assertArrayNotHasKey(self::KEY_PREFIX.'projects:p1', $this->heldLocks);
    }

    public function testWithKeyUsesRawKey(): void
    {
        $custom = 'lock:test:custom-key';
        $called = false;
        $lock = $this->makeLock();
        $lock->withKey($custom, function () use (&$called) {
            $called = true;
        }, target: 'custom');

        $this->assertTrue($called);
        $this->assertArrayNotHasKey($custom, $this->heldLocks);
    }

    public function testTryWithKeySkipsGivenKeyOnContention(): void
    {
        $custom = 'lock:test:contended';
        $this->heldLocks[$custom] = true;

        $lock = $this->makeLock();
        $called = false;
        $lock->tryWithKey($custom, function () use (&$called): void {
            $called = true;
        }, target: 'custom', ttl: 5);

        $this->assertFalse($called);
    }

    public function testWithKeyThrowsGivenKeyOnContention(): void
    {
        $custom = 'lock:test:contended';
        $this->heldLocks[$custom] = true;

        $lock = $this->makeLock();
        $this->expectException(Exception::class);
        $lock->withKey($custom, fn () => null, target: 'custom', ttl: 5, waitTimeout: 0.1);
    }

    public function testDisabledModeRunsCallbackUnlocked(): void
    {
        $previous = \getenv('_APP_LOCKING_ENABLED');
        \putenv('_APP_LOCKING_ENABLED=disabled');
        try {
            $key = self::KEY_PREFIX.'keys:k1';
            $this->heldLocks[$key] = true;

            $called = false;
            $lock = $this->makeLock();
            $lock->tryWithKey($key, function () use (&$called) {
                $called = true;
            }, target: 'keys');

            $this->assertTrue($called);
            $this->assertArrayHasKey($key, $this->heldLocks);
        } finally {
            $previous === false ? \putenv('_APP_LOCKING_ENABLED') : \putenv('_APP_LOCKING_ENABLED='.$previous);
        }
    }

    public function testPoolCheckoutExceptionRunsCallbackUnlocked(): void
    {
        $lock = new Lock(
            fn (string $key, int $ttl, \Closure $callback): mixed => throw new \Exception('Pool \'lock\' is empty'),
            new NoTelemetry(),
            null,
            $this->project,
        );

        $called = false;
        $lock->tryWithKey(self::KEY_PREFIX.'keys:k1', function () use (&$called) {
            $called = true;
        }, target: 'keys');

        $this->assertTrue($called);
    }

    public function testNonPoolExceptionIsNotSwallowed(): void
    {
        $lock = new Lock(
            fn (string $key, int $ttl, \Closure $callback): mixed => throw new \RuntimeException('unexpected'),
            new NoTelemetry(),
            null,
            $this->project,
        );

        $this->expectException(\RuntimeException::class);
        $lock->tryWithKey(self::KEY_PREFIX.'keys:k1', fn () => null, target: 'keys');
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

final class LockingRedisException extends \Exception
{
}

final class RecordingLoggerAdapter extends LoggerAdapter
{
    /**
     * @var list<Log>
     */
    public array $logs = [];

    public static function getName(): string
    {
        return 'recording';
    }

    public function push(Log $log): int
    {
        $this->logs[] = $log;

        return 200;
    }

    public function getSupportedTypes(): array
    {
        return [
            Log::TYPE_WARNING,
        ];
    }

    public function getSupportedEnvironments(): array
    {
        return [
            Log::ENVIRONMENT_PRODUCTION,
            Log::ENVIRONMENT_STAGING,
        ];
    }

    public function getSupportedBreadcrumbTypes(): array
    {
        return [];
    }
}
