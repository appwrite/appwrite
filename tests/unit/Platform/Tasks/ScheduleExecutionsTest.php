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
    #[DataProvider('scheduleProvider')]
    public function testScheduleMustExistAndRemainActive(Document $schedule, bool $expected): void
    {
        $task = new class () extends ScheduleExecutions {
            public function active(Database $dbForPlatform, string $scheduleId): bool
            {
                return $this->isScheduleActive($dbForPlatform, $scheduleId);
            }
        };
        $dbForPlatform = $this->createMock(Database::class);
        $dbForPlatform
            ->expects($this->once())
            ->method('getDocument')
            ->with('schedules', 'schedule-id')
            ->willReturn($schedule);

        $this->assertSame($expected, $task->active($dbForPlatform, 'schedule-id'));
    }

    /**
     * @return \Iterator<string, array{\Utopia\Database\Document, bool}>
     */
    public static function scheduleProvider(): \Iterator
    {
        yield 'active' => [new Document(['$id' => 'schedule-id', 'active' => true]), true];
        yield 'inactive' => [new Document(['$id' => 'schedule-id', 'active' => false]), false];
        yield 'missing' => [new Document(), false];
    }
}
