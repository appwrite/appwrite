<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Workers;

use Appwrite\Event\Message\Func as FunctionMessage;
use Appwrite\Platform\Workers\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;

require_once __DIR__ . '/../../../../app/init.php';

final class FunctionsTest extends TestCase
{
    #[DataProvider('functionIdProvider')]
    public function testScheduledExecutionIsHydratedBeforeItIsEnqueued(array $scheduleData, string $fallbackFunctionId, string $expectedFunctionId): void
    {
        $worker = $this->worker();
        $dbForPlatform = $this->createMock(Database::class);
        $schedule = new Document([
            '$id' => 'schedule-id',
            'active' => true,
            'data' => array_merge([
                'userId' => 'user-id',
                'body' => 'body',
                'path' => '/path',
                'headers' => ['x-test' => 'value'],
                'method' => 'PATCH',
            ], $scheduleData),
        ]);
        $claimed = false;

        $dbForPlatform
            ->expects($this->once())
            ->method('getDocument')
            ->with('schedules', 'schedule-id', [], true)
            ->willReturn($schedule);
        $dbForPlatform
            ->expects($this->once())
            ->method('withTransaction')
            ->willReturnCallback(fn (callable $callback): mixed => $callback());
        $dbForPlatform
            ->expects($this->once())
            ->method('updateDocument')
            ->with('schedules', 'schedule-id', $this->callback(function (Document $schedule) use (&$claimed): bool {
                $claimed = $schedule->getAttribute('active') === false;
                return $claimed;
            }))
            ->willReturn(new Document(['$id' => 'schedule-id', 'active' => false]));
        $dbForPlatform
            ->expects($this->once())
            ->method('deleteDocument')
            ->with('schedules', 'schedule-id')
            ->willReturn(true);

        $message = null;
        $project = new Document([
            '$id' => 'project-id',
            'accessedAt' => DateTime::now(),
        ]);
        $execution = new Document([
            '$id' => 'execution-id',
            'scheduleId' => 'schedule-id',
        ]);

        $this->assertTrue($worker->schedule(
            $dbForPlatform,
            $project,
            $execution,
            $fallbackFunctionId,
            function (FunctionMessage $candidate) use (&$claimed, &$message): void {
                $this->assertTrue($claimed, 'Schedule must be claimed before it is published');
                $message = $candidate;
            },
        ));

        $this->assertInstanceOf(FunctionMessage::class, $message);
        $this->assertSame('schedule', $message->type);
        $this->assertSame($expectedFunctionId, $message->functionId);
        $this->assertSame('user-id', $message->userId);
        $this->assertSame('execution-id', $message->execution->getId());
        $this->assertSame('', $message->execution->getAttribute('scheduleId', ''));
        $this->assertSame('body', $message->body);
        $this->assertSame('/path', $message->path);
        $this->assertSame(['x-test' => 'value'], $message->headers);
        $this->assertSame('PATCH', $message->method);
        $this->assertSame(
            0,
            $worker->projectAccessUpdates,
            'Access is recorded once per message in action(), so the republish path must not write it again'
        );
    }

    #[DataProvider('inactiveScheduleProvider')]
    public function testCancelledOrMissingScheduleIsNotEnqueued(Document $schedule): void
    {
        $worker = $this->worker();
        $dbForPlatform = $this->createMock(Database::class);
        $dbForPlatform
            ->expects($this->once())
            ->method('withTransaction')
            ->willReturnCallback(fn (callable $callback): mixed => $callback());
        $dbForPlatform
            ->expects($this->once())
            ->method('getDocument')
            ->with('schedules', 'schedule-id', [], true)
            ->willReturn($schedule);
        $dbForPlatform->expects($this->never())->method('updateDocument');
        $dbForPlatform->expects($this->never())->method('deleteDocument');

        $this->assertFalse($worker->schedule(
            $dbForPlatform,
            new Document(['$id' => 'project-id']),
            new Document(['$id' => 'execution-id', 'scheduleId' => 'schedule-id']),
            'function-id',
            fn () => $this->fail('Cancelled schedule was enqueued'),
        ));
    }

    public function testFailedPublishReleasesScheduleClaim(): void
    {
        $worker = $this->worker();
        $dbForPlatform = $this->createMock(Database::class);
        $updates = [];
        $dbForPlatform
            ->expects($this->once())
            ->method('withTransaction')
            ->willReturnCallback(fn (callable $callback): mixed => $callback());
        $dbForPlatform
            ->expects($this->once())
            ->method('getDocument')
            ->willReturn(new Document([
                '$id' => 'schedule-id',
                'active' => true,
                'data' => ['functionId' => 'function-id'],
            ]));
        $dbForPlatform
            ->expects($this->exactly(2))
            ->method('updateDocument')
            ->willReturnCallback(function (string $collection, string $id, Document $schedule) use (&$updates): Document {
                $updates[] = $schedule->getAttribute('active');
                return new Document(['$id' => $id, 'active' => $schedule->getAttribute('active')]);
            });
        $dbForPlatform->expects($this->never())->method('deleteDocument');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Queue unavailable');

        try {
            $worker->schedule(
                $dbForPlatform,
                new Document(['$id' => 'project-id', 'accessedAt' => DateTime::now()]),
                new Document(['$id' => 'execution-id', 'scheduleId' => 'schedule-id']),
                '',
                fn () => throw new \RuntimeException('Queue unavailable'),
            );
        } finally {
            $this->assertSame([false, true], $updates);
        }
    }

    public function testFailedPublishDoesNotReviveScheduleAfterConcurrentCancellation(): void
    {
        $store = new MemoryScheduleStore(new Document([
            '$id' => 'schedule-id',
            'active' => true,
            'data' => ['functionId' => 'function-id'],
        ]));
        $locks = new QueueingLock();
        $cancelled = false;

        try {
            $this->worker()->schedule(
                $this->scheduleDatabase($store),
                new Document(['$id' => 'project-id', 'accessedAt' => DateTime::now()]),
                new Document(['$id' => 'execution-id', 'scheduleId' => 'schedule-id']),
                'function-id',
                function () use ($store, $locks, &$cancelled): void {
                    $this->cancelSchedule($this->scheduleDatabase($store), $locks, 'schedule-id', $cancelled);
                    throw new \RuntimeException('Queue unavailable');
                },
                $locks,
            );
        } catch (\RuntimeException $error) {
            $this->assertSame('Queue unavailable', $error->getMessage());
        }

        $this->assertTrue($cancelled, 'Cancellation must succeed when publication was prevented');
        $this->assertTrue($store->document->isEmpty(), 'Failed publication must not revive a cancelled schedule');
    }

    public function testCancellationLosesOncePublicationSucceedsEvenIfCleanupFails(): void
    {
        $store = new MemoryScheduleStore(new Document([
            '$id' => 'schedule-id',
            'active' => true,
            'data' => ['functionId' => 'function-id'],
        ]));
        $store->failDeletes = true;
        $locks = new QueueingLock();
        $cancelled = false;

        try {
            $this->worker()->schedule(
                $this->scheduleDatabase($store),
                new Document(['$id' => 'project-id', 'accessedAt' => DateTime::now()]),
                new Document(['$id' => 'execution-id', 'scheduleId' => 'schedule-id']),
                'function-id',
                function () use ($store, $locks, &$cancelled): void {
                    $this->cancelSchedule($this->scheduleDatabase($store), $locks, 'schedule-id', $cancelled);
                },
                $locks,
            );
            $this->fail('Cleanup failure must surface after a successful publish');
        } catch (\RuntimeException $error) {
            $this->assertSame('Failed to remove claimed execution schedule', $error->getMessage());
        }

        $this->assertFalse($cancelled, '204 is not allowed after the function job is already queued');
        $this->assertFalse($store->document->isEmpty());
        $this->assertFalse($store->document->getAttribute('active'));
    }

    /**
     * @param callable(string, int, callable, float): mixed $locks
     */
    private function cancelSchedule(Database $dbForPlatform, callable $locks, string $scheduleId, ?bool &$result = null): ?bool
    {
        return $locks(
            'lock:platform:schedules:' . $scheduleId,
            30,
            function () use ($dbForPlatform, $scheduleId, &$result): bool {
                $result = $dbForPlatform->withTransaction(function () use ($dbForPlatform, $scheduleId): bool {
                    $schedule = $dbForPlatform->getDocument('schedules', $scheduleId, forUpdate: true);
                    if ($schedule->isEmpty() || !$schedule->getAttribute('active', false)) {
                        return false;
                    }

                    return $dbForPlatform->deleteDocument('schedules', $scheduleId);
                });

                return $result;
            },
            10.0,
        );
    }

    /**
     * @return \Iterator<string, array{array<string, string>, string, string}>
     */
    public static function functionIdProvider(): \Iterator
    {
        yield 'current schedule data' => [['functionId' => 'function-id'], 'legacy-function-id', 'function-id'];
        yield 'legacy execution resource' => [[], 'legacy-function-id', 'legacy-function-id'];
    }

    /**
     * @return \Iterator<string, array{\Utopia\Database\Document}>
     */
    public static function inactiveScheduleProvider(): \Iterator
    {
        yield 'inactive' => [new Document(['$id' => 'schedule-id', 'active' => false])];
        yield 'missing' => [new Document()];
    }

    private function worker(): TestFunctions
    {
        return new TestFunctions();
    }

    private function scheduleDatabase(MemoryScheduleStore $store): Database
    {
        $db = $this->createMock(Database::class);
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
            if ($store->failDeletes || $store->document->isEmpty() || $store->document->getId() !== $id) {
                return false;
            }

            $store->document = new Document();

            return true;
        });

        return $db;
    }
}

final class TestFunctions extends Functions
{
    public int $projectAccessUpdates = 0;

    /**
     * @param callable(string, int, callable, float): mixed|null $locks
     */
    public function schedule(Database $dbForPlatform, Document $project, Document $execution, string $functionId, callable $enqueue, ?callable $locks = null): bool
    {
        return $this->enqueueScheduledExecution($dbForPlatform, $project, $execution, $functionId, $enqueue, $locks);
    }

    protected function updateProjectAccess(Document $project, Database $dbForPlatform): void
    {
        $this->projectAccessUpdates++;
    }
}

/**
 * Single-thread stand-in for a blocking per-key lock: waiters run after release.
 */
final class QueueingLock
{
    /** @var array<string, bool> */
    private array $held = [];

    /** @var array<string, list<callable(): mixed>> */
    private array $waiters = [];

    public function __invoke(string $key, int $ttl, callable $callback, float $timeout = 0.0): mixed
    {
        if ($this->held[$key] ?? false) {
            $result = null;
            $this->waiters[$key][] = function () use ($callback, &$result): mixed {
                return $result = $callback();
            };

            return $result;
        }

        $this->held[$key] = true;
        try {
            return $callback();
        } finally {
            $this->held[$key] = false;
            $waiters = $this->waiters[$key] ?? [];
            $this->waiters[$key] = [];
            foreach ($waiters as $waiter) {
                ($this)($key, $ttl, $waiter, $timeout);
            }
        }
    }
}

final class MemoryScheduleStore
{
    public bool $failDeletes = false;

    public function __construct(public Document $document)
    {
    }
}
