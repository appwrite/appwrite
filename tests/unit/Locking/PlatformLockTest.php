<?php

declare(strict_types=1);

namespace Tests\Unit\Locking;

use Appwrite\Locking\Lock;
use Appwrite\Locking\PlatformLock;
use PHPUnit\Framework\TestCase;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Lock\Lock as UtopiaLock;
use Utopia\Logger\Log;
use Utopia\Telemetry\Adapter\None as NoTelemetry;

final class PlatformLockTest extends TestCase
{
    /**
     * @var array<string, bool>
     */
    private array $heldLocks = [];

    private Document $project;

    private Authorization $authorization;

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
    }

    private function makePlatformLock(?Database $db = null): PlatformLock
    {
        return new PlatformLock(
            new Lock(
                fn (string $key, int $ttl, \Closure $callback): mixed => $callback(new PlatformMemoryLock($key, $this->heldLocks)),
                new NoTelemetry(),
                new Log(),
                null,
                $this->project,
            ),
            $db ?? $this->createStub(Database::class),
            $this->authorization,
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

        $lock = $this->makePlatformLock($db);
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

        $lock = $this->makePlatformLock($db);
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

        $lock = $this->makePlatformLock($db);
        $lock->set('projects', 'p1', 'mcpAccessedAt', '2024-06-01 12:00:00');

        $this->assertArrayHasKey($heldKey, $this->heldLocks);
    }
}

final class PlatformMemoryLock implements UtopiaLock
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
