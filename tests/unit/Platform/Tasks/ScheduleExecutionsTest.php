<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Tasks;

use Appwrite\Platform\Tasks\ScheduleExecutions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine as Co;
use Swoole\Coroutine\WaitGroup;
use Utopia\Database\Database;
use Utopia\Database\Document;

final class ScheduleExecutionsTest extends TestCase
{
    public function testActiveScheduleIsClaimedBeforeItIsEnqueuedAndRemoved(): void
    {
        $task = $this->task();
        $dbForPlatform = $this->createMock(Database::class);
        $claimed = false;
        $dbForPlatform
            ->expects($this->exactly(2))
            ->method('withTransaction')
            ->willReturnCallback(fn (callable $callback): mixed => $callback());
        $dbForPlatform
            ->expects($this->exactly(2))
            ->method('getDocument')
            ->with('schedules', 'schedule-id', [], true)
            ->willReturnOnConsecutiveCalls(
                new Document(['$id' => 'schedule-id', 'active' => true]),
                new Document(['$id' => 'schedule-id', 'active' => false]),
            );
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

        $enqueued = false;
        $this->assertTrue($task->enqueue($dbForPlatform, 'schedule-id', function () use (&$claimed, &$enqueued): void {
            $this->assertTrue($claimed, 'Schedule must be claimed before it is published');
            $enqueued = true;
        }));
        $this->assertTrue($enqueued);
    }

    #[DataProvider('inactiveScheduleProvider')]
    public function testCancelledOrMissingScheduleIsNotEnqueued(Document $schedule): void
    {
        $task = $this->task();
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

        $this->assertFalse($task->enqueue(
            $dbForPlatform,
            'schedule-id',
            fn () => $this->fail('Cancelled schedule was enqueued'),
        ));
    }

    public function testCancellationAfterClaimPreventsPublish(): void
    {
        $task = $this->task();
        $dbForPlatform = $this->createMock(Database::class);
        $dbForPlatform
            ->expects($this->exactly(2))
            ->method('withTransaction')
            ->willReturnCallback(fn (callable $callback): mixed => $callback());
        $dbForPlatform
            ->expects($this->exactly(2))
            ->method('getDocument')
            ->with('schedules', 'schedule-id', [], true)
            ->willReturnOnConsecutiveCalls(
                new Document(['$id' => 'schedule-id', 'active' => true]),
                new Document(),
            );
        $dbForPlatform
            ->expects($this->once())
            ->method('updateDocument')
            ->willReturn(new Document(['$id' => 'schedule-id', 'active' => false]));
        $dbForPlatform->expects($this->never())->method('deleteDocument');

        $this->assertFalse($task->enqueue(
            $dbForPlatform,
            'schedule-id',
            fn () => $this->fail('Cancelled schedule was enqueued'),
        ));
    }

    public function testFailedPublishReleasesScheduleClaim(): void
    {
        $task = $this->task();
        $dbForPlatform = $this->createMock(Database::class);
        $updates = [];
        $dbForPlatform
            ->expects($this->exactly(2))
            ->method('withTransaction')
            ->willReturnCallback(fn (callable $callback): mixed => $callback());
        $dbForPlatform
            ->expects($this->exactly(2))
            ->method('getDocument')
            ->with('schedules', 'schedule-id', [], true)
            ->willReturnOnConsecutiveCalls(
                new Document(['$id' => 'schedule-id', 'active' => true]),
                new Document(['$id' => 'schedule-id', 'active' => false]),
            );
        $dbForPlatform
            ->expects($this->exactly(2))
            ->method('updateDocument')
            ->willReturnCallback(function (string $collection, string $id, Document $schedule) use (&$updates): Document {
                $this->assertSame('schedules', $collection);
                $this->assertSame('schedule-id', $id);
                $updates[] = $schedule->getAttribute('active');
                return new Document(['$id' => $id, 'active' => $schedule->getAttribute('active')]);
            });
        $dbForPlatform->expects($this->never())->method('deleteDocument');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Queue unavailable');

        try {
            $task->enqueue(
                $dbForPlatform,
                'schedule-id',
                fn () => throw new \RuntimeException('Queue unavailable'),
            );
        } finally {
            $this->assertSame([false, true], $updates);
        }
    }

    public function testEnqueueConcurrencyIsBounded(): void
    {
        $task = $this->task();
        $active = 0;
        $peak = 0;

        \Swoole\Coroutine\run(function () use ($task, &$active, &$peak): void {
            $waitGroup = new WaitGroup();

            for ($i = 0; $i < $task->concurrency() + 5; $i++) {
                $waitGroup->add();

                \go(function () use ($task, $waitGroup, &$active, &$peak): void {
                    try {
                        $task->withSlot(function () use (&$active, &$peak): void {
                            $active++;
                            $peak = max($peak, $active);
                            Co::sleep(0.01);
                            $active--;
                        });
                    } finally {
                        $waitGroup->done();
                    }
                });
            }

            $waitGroup->wait();
        });

        $this->assertSame($task->concurrency(), $peak);
    }

    /**
     * @return \Iterator<string, array{\Utopia\Database\Document}>
     */
    public static function inactiveScheduleProvider(): \Iterator
    {
        yield 'inactive' => [new Document(['$id' => 'schedule-id', 'active' => false])];
        yield 'missing' => [new Document()];
    }

    private function task(): TestScheduleExecutions
    {
        return new TestScheduleExecutions();
    }
}

final class TestScheduleExecutions extends ScheduleExecutions
{
    public function enqueue(Database $dbForPlatform, string $scheduleId, callable $enqueue): bool
    {
        return $this->enqueueIfActive($dbForPlatform, $scheduleId, $enqueue);
    }

    public function withSlot(callable $callback): mixed
    {
        return $this->withEnqueueSlot($callback);
    }

    public function concurrency(): int
    {
        return self::ENQUEUE_CONCURRENCY;
    }
}
