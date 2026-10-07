<?php

declare(strict_types=1);

namespace Tests\Unit\Schedule;

use Appwrite\Extend\Exception;
use Appwrite\Schedule\Execution;
use Fiber;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Lock\Exception\Contention;

final class ExecutionTest extends TestCase
{
    public function testCancelBeforePublishPreventsEnqueue(): void
    {
        $store = $this->store(active: true);
        $schedules = $this->schedules(new SuspendingLock());

        $database = $this->database($store);
        $this->assertTrue($schedules->cancel($database, 'schedule-id'));
        $this->assertFalse($schedules->publish(
            $database,
            new Document(['$id' => 'project-id']),
            new Document(['$id' => 'execution-id', 'scheduleId' => 'schedule-id']),
            'function-id',
            fn () => $this->fail('Cancelled schedule was enqueued'),
        ));
        $this->assertTrue($store->document->isEmpty());
    }

    public function testWaitingCancelSucceedsWhenPublishFails(): void
    {
        $store = $this->store(active: true);
        $lock = new SuspendingLock();
        $schedules = $this->schedules($lock);
        $database = $this->database($store);
        $cancelled = null;
        $canceller = new Fiber(function () use ($schedules, $database, &$cancelled): void {
            $cancelled = $schedules->cancel($database, 'schedule-id');
        });

        try {
            $schedules->publish(
                $database,
                new Document(['$id' => 'project-id']),
                new Document(['$id' => 'execution-id', 'scheduleId' => 'schedule-id']),
                'function-id',
                function () use ($canceller): void {
                    $canceller->start();
                    throw new \RuntimeException('Queue unavailable');
                },
            );
            $this->fail('A failed publish must surface');
        } catch (\RuntimeException $error) {
            $this->assertSame('Queue unavailable', $error->getMessage());
        }

        $this->assertTrue($cancelled, 'Cancellation must succeed once publication was prevented');
        $this->assertTrue($store->document->isEmpty(), 'Failed publication must not revive a cancelled schedule');
    }

    public function testWaitingCancelLosesWhenPublishSucceeds(): void
    {
        $store = $this->store(active: true);
        $store->failNextDelete = true;
        $schedules = $this->schedules(new SuspendingLock());
        $database = $this->database($store);
        $cancelled = null;
        $canceller = new Fiber(function () use ($schedules, $database, &$cancelled): void {
            $cancelled = $schedules->cancel($database, 'schedule-id');
        });

        try {
            $schedules->publish(
                $database,
                new Document(['$id' => 'project-id']),
                new Document(['$id' => 'execution-id', 'scheduleId' => 'schedule-id']),
                'function-id',
                function () use ($canceller): void {
                    $canceller->start();
                },
            );
            $this->fail('Cleanup failure must surface after a successful publish');
        } catch (\RuntimeException $error) {
            $this->assertSame('Failed to remove claimed execution schedule', $error->getMessage());
        }

        $this->assertFalse($cancelled, 'Cancellation loses once the function job is queued');
        $this->assertFalse($store->document->isEmpty());
        $this->assertFalse($store->document->getAttribute('active'));
        $this->assertSame(1, $store->deletes);
    }

    public function testClaimedScheduleIsNotDeleted(): void
    {
        $store = $this->store(active: false);
        $schedules = $this->schedules(new SuspendingLock());

        $this->assertFalse($schedules->cancel($this->database($store), 'schedule-id'));
        $this->assertFalse($store->document->isEmpty());
        $this->assertSame(0, $store->deletes);
    }

    public function testLockTimeoutIsRetryableAndLeavesTheSchedule(): void
    {
        $store = $this->store(active: true);
        $schedules = new Execution(static function (string $key, int $ttl, callable $callback, float $timeout = 0.0): never {
            throw new Contention("Failed to acquire distributed lock: {$key}");
        });

        try {
            $schedules->cancel($this->database($store), 'schedule-id');
            $this->fail('A lock timeout must not look like a completed cancel');
        } catch (Exception $error) {
            $this->assertSame(Exception::GENERAL_RESOURCE_LOCKED, $error->getType());
        }

        $this->assertTrue($store->document->getAttribute('active'));
        $this->assertSame(0, $store->deletes);
    }

    private function schedules(SuspendingLock $lock): Execution
    {
        return new Execution(\Closure::fromCallable($lock));
    }

    private function store(bool $active): ScheduleStore
    {
        return new ScheduleStore(new Document([
            '$id' => 'schedule-id',
            'active' => $active,
            'data' => ['functionId' => 'function-id'],
        ]));
    }

    private function database(ScheduleStore $store): Database
    {
        $db = $this->createStub(Database::class);
        $db->method('withTransaction')->willReturnCallback(fn (callable $callback): mixed => $callback());
        $db->method('getDocument')->willReturnCallback(function (string $collection, string $id) use ($store): Document {
            if ($store->document->isEmpty() || $store->document->getId() !== $id) {
                return new Document();
            }

            return $store->document;
        });
        $db->method('updateDocument')->willReturnCallback(function (string $collection, string $id, Document $update) use ($store): Document {
            if ($store->document->isEmpty() || $store->document->getId() !== $id) {
                return new Document();
            }

            foreach ($update->getArrayCopy() as $key => $value) {
                $store->document->setAttribute($key, $value);
            }

            return $store->document;
        });
        $db->method('deleteDocument')->willReturnCallback(function (string $collection, string $id) use ($store): bool {
            $store->deletes++;
            if ($store->failNextDelete) {
                $store->failNextDelete = false;

                return false;
            }

            if ($store->document->isEmpty() || $store->document->getId() !== $id) {
                return false;
            }

            $store->document = new Document();

            return true;
        });

        return $db;
    }
}

/**
 * Blocks the waiter fiber until the holder releases, then returns the waiter's own result.
 */
final class SuspendingLock
{
    /** @var array<string, bool> */
    private array $held = [];

    /** @var array<string, list<Fiber>> */
    private array $waiters = [];

    public function __invoke(string $key, int $ttl, callable $callback, float $timeout = 0.0): mixed
    {
        while ($this->held[$key] ?? false) {
            $fiber = Fiber::getCurrent();
            if (!$fiber instanceof Fiber) {
                throw new \RuntimeException('Schedule lock contention on the main fiber');
            }

            $this->waiters[$key][] = $fiber;
            Fiber::suspend();
        }

        $this->held[$key] = true;
        try {
            return $callback();
        } finally {
            $this->held[$key] = false;
            $waiter = null;
            if (!empty($this->waiters[$key])) {
                $waiter = array_shift($this->waiters[$key]);
            }
            if ($waiter instanceof Fiber && !$waiter->isTerminated()) {
                $waiter->resume();
            }
        }
    }
}

final class ScheduleStore
{
    public int $deletes = 0;

    public bool $failNextDelete = false;

    public function __construct(public Document $document)
    {
    }
}
