<?php

declare(strict_types=1);

namespace Tests\Unit\Locking;

use Appwrite\Extend\Exception;
use Appwrite\Locking\Lock;
use PHPUnit\Framework\TestCase;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Lock\Lock as LockBackend;
use Utopia\Logger\Log;
use Utopia\Telemetry\Adapter\None as NoTelemetry;

final class LockTest extends TestCase
{
    /**
     * @var array<string, bool>
     */
    private array $heldLocks = [];

    private Document $project;

    private Authorization $authorization;

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
        $this->authorization = new Authorization();
        $this->log = new Log();
    }

    private function makeLock(?Database $db = null, ?Authorization $auth = null): Lock
    {
        return new Lock(
            $this->withLock(),
            new NoTelemetry(),
            $db ?? $this->createStub(Database::class),
            $auth ?? $this->authorization,
            $this->log,
            null,
            $this->project,
        );
    }

    private function withLock(): \Closure
    {
        return fn (string $key, int $ttl, \Closure $callback): mixed => $callback(new MemoryLock($key, $this->heldLocks));
    }

    public function test_set_uses_per_attribute_key_and_auth_skipped_update(): void
    {
        $captured = null;
        $db = $this->createMock(Database::class);
        $db->expects($this->once())
            ->method('updateDocument')
            ->with('projects', 'p1', $this->callback(function (Document $doc) use (&$captured) {
                $captured = $doc->getArrayCopy();

                return true;
            }))
            ->willReturnArgument(2);

        $lock = $this->makeLock($db);
        $lock->set('projects', 'p1', 'accessedAt', '2024-06-01 12:00:00');

        $this->assertSame(['accessedAt' => '2024-06-01 12:00:00'], $captured);
        $this->assertArrayNotHasKey(self::KEY_PREFIX.'projects:p1:accessedAt', $this->heldLocks);
    }

    public function test_set_skips_on_contention(): void
    {
        $key = self::KEY_PREFIX.'projects:p1:accessedAt';
        $this->heldLocks[$key] = true;

        $db = $this->createMock(Database::class);
        $db->expects($this->never())->method('updateDocument');

        $lock = $this->makeLock($db);
        $lock->set('projects', 'p1', 'accessedAt', '2024-06-01 12:00:00');

        $this->assertArrayHasKey($key, $this->heldLocks);
    }

    public function test_set_different_attributes_do_not_compete(): void
    {
        $heldKey = self::KEY_PREFIX.'projects:p1:accessedAt';
        $this->heldLocks[$heldKey] = true;

        $db = $this->createMock(Database::class);
        $db->expects($this->once())
            ->method('updateDocument')
            ->with('projects', 'p1', $this->isInstanceOf(Document::class))
            ->willReturnArgument(2);

        $lock = $this->makeLock($db);
        $lock->set('projects', 'p1', 'mcpAccessedAt', '2024-06-01 12:00:00');

        $this->assertArrayHasKey($heldKey, $this->heldLocks);
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

    public function test_with_key_or_fail_flag_throws_on_contention(): void
    {
        $custom = 'lock:test:contended';
        $this->heldLocks[$custom] = true;

        $lock = $this->makeLock();
        $this->expectException(Exception::class);
        $lock->withKey($custom, fn () => null, ttl: 5, orFail: true, waitTimeout: 0.1);
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
            $this->createStub(Database::class),
            $this->authorization,
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
}

final class MemoryLock implements LockBackend
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
            return null;
        }

        try {
            return $callback();
        } finally {
            $this->release();
        }
    }
}
