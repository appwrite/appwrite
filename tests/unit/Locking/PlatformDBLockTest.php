<?php

declare(strict_types=1);

namespace Tests\Unit\Locking;

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

final class PlatformDBLockTest extends TestCase
{
    /**
     * @var array<string, bool>
     */
    private array $heldLocks = [];

    private Log $log;

    private const PROJECT_SEQUENCE = '42';

    private const KEY_PREFIX = 'lock:platform:'.self::PROJECT_SEQUENCE.':';

    protected function setUp(): void
    {
        Config::setParam('errors', require __DIR__.'/../../../app/config/errors.php');

        $this->heldLocks = [];
        $this->log = new Log();
    }

    private function makeLock(?Document $project = null): Lock
    {
        return new Lock(
            fn (string $key, int $ttl, \Closure $callback): mixed => $callback(new PlatformDBLockMemoryLock($key, $this->heldLocks)),
            new NoTelemetry(),
            $this->log,
            null,
            $project ?? new Document([
                '$id' => 'test-project',
                '$sequence' => self::PROJECT_SEQUENCE,
            ]),
        );
    }

    public function testTryUpdateAttributeUsesAttributeKey(): void
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

    public function testTryRunUsesDocumentKey(): void
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

    public function testTryRunSkipsOnContention(): void
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

    public function testTryUpdateDocumentUsesDocumentKey(): void
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
        $this->assertArrayNotHasKey(self::KEY_PREFIX.'keys:k1', $this->heldLocks);
    }

    public function testTryUpdateDocumentSkipsOnContention(): void
    {
        $this->heldLocks[self::KEY_PREFIX.'keys:k1'] = true;

        $dbForPlatform = $this->createMock(Database::class);
        $dbForPlatform
            ->expects($this->never())
            ->method('updateDocument');

        $platformDBLock = new PlatformDBLock($this->makeLock(), $dbForPlatform, new Authorization());

        $this->assertNotInstanceOf(Document::class, $platformDBLock->tryUpdateDocument('keys', 'k1', new Document(['accessedAt' => 'now'])));
    }

    public function testTryUpdateAttributeCanUseExplicitProjectScope(): void
    {
        $project = new Document([
            '$id' => 'routed-project',
            '$sequence' => '84',
        ]);

        $this->heldLocks['lock:platform:84:projects:routed-project:accessedAt'] = true;

        $dbForPlatform = $this->createMock(Database::class);
        $dbForPlatform
            ->expects($this->never())
            ->method('updateDocument');

        $platformDBLock = new PlatformDBLock($this->makeLock(new Document()), $dbForPlatform, new Authorization());

        $this->assertNotInstanceOf(Document::class, $platformDBLock->tryUpdateAttribute(
            'projects',
            'routed-project',
            'accessedAt',
            'now',
            $project
        ));
    }
}

final class PlatformDBLockMemoryLock implements UtopiaLock
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
