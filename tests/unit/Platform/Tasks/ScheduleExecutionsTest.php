<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Tasks;

use Appwrite\Platform\Tasks\ScheduleExecutions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Database;
use Utopia\Database\Document;

final class ScheduleExecutionsTest extends TestCase
{
    public function testActiveScheduleIsLockedWhileEnqueuedAndRemoved(): void
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
            ->willReturn(new Document(['$id' => 'schedule-id', 'active' => true]));
        $dbForPlatform
            ->expects($this->once())
            ->method('deleteDocument')
            ->with('schedules', 'schedule-id')
            ->willReturn(true);

        $enqueued = false;
        $this->assertTrue($task->enqueue($dbForPlatform, 'schedule-id', function () use (&$enqueued): void {
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
        $dbForPlatform->expects($this->never())->method('deleteDocument');

        $this->assertFalse($task->enqueue(
            $dbForPlatform,
            'schedule-id',
            fn () => $this->fail('Cancelled schedule was enqueued'),
        ));
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
}
