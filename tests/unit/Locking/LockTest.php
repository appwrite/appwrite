<?php

declare(strict_types=1);

namespace Tests\Unit\Locking;

use Appwrite\Extend\Exception;
use Appwrite\Locking\Lock;
use PHPUnit\Framework\TestCase;
use Redis;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\DSN\DSN;
use Utopia\Logger\Log;
use Utopia\Telemetry\Adapter\None as NoTelemetry;

final class LockTest extends TestCase
{
    private Redis $redis;

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
        [$host, $port] = $this->getRedisConnection();

        $this->redis = new Redis();
        $this->redis->connect($host, $port, 1.0);

        $this->project = new Document([
            '$id' => 'test-project',
            '$sequence' => self::PROJECT_SEQUENCE,
        ]);
        $this->authorization = new Authorization();
        $this->log = new Log();

        $this->cleanupKeys();
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function getRedisConnection(): array
    {
        $dsn = \getenv('_APP_CONNECTIONS_CACHE') ?: '';

        if ($dsn !== '') {
            $dsn = \explode(',', $dsn, 2)[0];
            $dsn = \explode('=', $dsn, 2)[1] ?? $dsn;
            $parsed = new DSN($dsn);

            return [$parsed->getHost(), (int) ($parsed->getPort() ?? '6379')];
        }

        return [
            \getenv('_APP_REDIS_HOST') ?: 'redis',
            (int) (\getenv('_APP_REDIS_PORT') ?: 6379),
        ];
    }

    protected function tearDown(): void
    {
        if (isset($this->redis) && $this->redis->isConnected()) {
            $this->cleanupKeys();
        }
    }

    private function cleanupKeys(): void
    {
        foreach ($this->redis->keys(self::KEY_PREFIX.'*') as $key) {
            $this->redis->del($key);
        }
        // also clean keys produced by withKey() tests
        foreach ($this->redis->keys('lock:test:*') as $key) {
            $this->redis->del($key);
        }
    }

    private function makeLock(?Database $db = null, ?Authorization $auth = null): Lock
    {
        return new Lock(
            $this->redis,
            new NoTelemetry(),
            $db ?? $this->createStub(Database::class),
            $auth ?? $this->authorization,
            $this->log,
            null,
            $this->project,
        );
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
        $this->assertSame(0, $this->redis->exists(self::KEY_PREFIX.'projects:p1:accessedAt'));
    }

    public function test_set_skips_on_contention(): void
    {
        $key = self::KEY_PREFIX.'projects:p1:accessedAt';
        $this->redis->set($key, 'other-owner', ['NX', 'EX' => 30]);

        $db = $this->createMock(Database::class);
        $db->expects($this->never())->method('updateDocument');

        $lock = $this->makeLock($db);
        $lock->set('projects', 'p1', 'accessedAt', '2024-06-01 12:00:00');

        $this->assertSame('other-owner', $this->redis->get($key));
    }

    public function test_set_different_attributes_do_not_compete(): void
    {
        // Hold accessedAt
        $heldKey = self::KEY_PREFIX.'projects:p1:accessedAt';
        $this->redis->set($heldKey, 'other-owner', ['NX', 'EX' => 30]);

        // mcpAccessedAt should still be acquirable
        $db = $this->createMock(Database::class);
        $db->expects($this->once())
            ->method('updateDocument')
            ->with('projects', 'p1', $this->isInstanceOf(Document::class))
            ->willReturnArgument(2);

        $lock = $this->makeLock($db);
        $lock->set('projects', 'p1', 'mcpAccessedAt', '2024-06-01 12:00:00');

        $this->assertSame('other-owner', $this->redis->get($heldKey));
    }

    public function test_run_uses_per_document_key_and_invokes_callback(): void
    {
        $called = false;
        $lock = $this->makeLock();
        $lock->run('keys', 'k1', function () use (&$called) {
            $called = true;
        });

        $this->assertTrue($called);
        $this->assertSame(0, $this->redis->exists(self::KEY_PREFIX.'keys:k1'));
    }

    public function test_run_skips_on_contention(): void
    {
        $key = self::KEY_PREFIX.'keys:k1';
        $this->redis->set($key, 'other-owner', ['NX', 'EX' => 30]);

        $called = false;
        $lock = $this->makeLock();
        $lock->run('keys', 'k1', function () use (&$called) {
            $called = true;
        });

        $this->assertFalse($called);
        $this->assertSame('other-owner', $this->redis->get($key));
    }

    public function test_run_or_fail_throws_on_contention(): void
    {
        $key = self::KEY_PREFIX.'projects:p1';
        $this->redis->set($key, 'other-owner', ['NX', 'EX' => 30]);

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
        $this->assertSame(0, $this->redis->exists(self::KEY_PREFIX.'projects:p1'));
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
        $this->assertSame(0, $this->redis->exists($custom));
    }

    public function test_with_key_or_fail_flag_throws_on_contention(): void
    {
        $custom = 'lock:test:contended';
        $this->redis->set($custom, 'other', ['NX', 'EX' => 30]);

        $lock = $this->makeLock();
        $this->expectException(Exception::class);
        $lock->withKey($custom, fn () => null, ttl: 5, orFail: true, waitTimeout: 0.1);
    }

    public function test_disabled_mode_runs_callback_unlocked(): void
    {
        $previous = \getenv('_APP_LOCKING_ENABLED');
        \putenv('_APP_LOCKING_ENABLED=disabled');
        try {
            // Even when the key is already held, the callback must still run.
            $key = self::KEY_PREFIX.'keys:k1';
            $this->redis->set($key, 'other-owner', ['NX', 'EX' => 30]);

            $called = false;
            $lock = $this->makeLock();
            $lock->run('keys', 'k1', function () use (&$called) {
                $called = true;
            });

            $this->assertTrue($called);
            $this->assertSame('other-owner', $this->redis->get($key));
        } finally {
            $previous === false ? \putenv('_APP_LOCKING_ENABLED') : \putenv('_APP_LOCKING_ENABLED='.$previous);
        }
    }

    public function test_project_without_sequence_falls_back_to_unknown(): void
    {
        $emptyProject = new Document();
        $lock = new Lock(
            $this->redis,
            new NoTelemetry(),
            $this->createStub(Database::class),
            $this->authorization,
            $this->log,
            null,
            $emptyProject,
        );

        // Pre-acquire the lock at the 'unknown' projectInternalId path.
        $key = 'lock:platform:unknown:keys:k1';
        $this->redis->set($key, 'held', ['NX', 'EX' => 30]);

        $called = false;
        $lock->run('keys', 'k1', function () use (&$called) {
            $called = true;
        });
        $this->assertFalse($called, 'Lock without project sequence should hash to the unknown bucket');

        $this->redis->del($key);
    }
}
