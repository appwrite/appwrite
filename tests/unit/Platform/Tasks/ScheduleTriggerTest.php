<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Tasks;

use Appwrite\Platform\Tasks\ScheduleExecutions;
use Appwrite\Platform\Tasks\ScheduleFunctions;
use Appwrite\Platform\Tasks\ScheduleMessages;
use PHPUnit\Framework\TestCase;
use Utopia\Schedule\Trigger;
use Utopia\Schedule\Trigger\At;
use Utopia\Schedule\Trigger\Cron;

/**
 * What each task means by the `schedule` attribute it stores, which is the
 * whole of its contribution to selection: the library owns the windowing.
 */
final class ScheduleTriggerTest extends TestCase
{
    public function testFunctionSchedulesAreCronExpressions(): void
    {
        $trigger = $this->trigger(new ScheduleFunctions(), ['schedule' => '*/15 * * * *']);

        $this->assertInstanceOf(Cron::class, $trigger);
        $this->assertTrue($trigger->recurring());
        $this->assertSame(
            ['03:15:00', '03:30:00'],
            $this->dues($trigger, '2026-08-18 03:00:59.500000', '2026-08-18 03:31:00.500000'),
        );
    }

    public function testAnUnusableCronExpressionIsRejectedWhenTheRowIsBuilt(): void
    {
        // Rejected once, where the row is turned into a schedule, instead of
        // silently matching nothing on every tick forever.
        $this->expectException(\InvalidArgumentException::class);

        $this->trigger(new ScheduleFunctions(), ['schedule' => '0 0 31 2 *']);
    }

    public function testExecutionSchedulesAreASingleStoredMoment(): void
    {
        $trigger = $this->trigger(new ScheduleExecutions(), ['schedule' => '2026-08-18 03:05:00.000']);

        $this->assertInstanceOf(At::class, $trigger);
        $this->assertFalse($trigger->recurring(), 'a one-shot is retired once delivered');
        $this->assertSame(
            ['03:05:00'],
            $this->dues($trigger, '2026-08-18 03:00:00.000000', '2026-08-18 03:10:00.000000'),
        );
        $this->assertSame(
            [],
            $this->dues($trigger, '2026-08-18 03:05:00.000001', '2026-08-18 03:10:00.000000'),
            'a window opening after the moment carries nothing',
        );
    }

    public function testMessageSchedulesAreASingleStoredMoment(): void
    {
        $trigger = $this->trigger(new ScheduleMessages(), ['schedule' => '2026-08-18 09:30:00.000']);

        $this->assertInstanceOf(At::class, $trigger);
        $this->assertSame(
            ['09:30:00'],
            $this->dues($trigger, '2026-08-18 09:00:00.000000', '2026-08-18 10:00:00.000000'),
        );
    }

    /**
     * Messages must not go out before they are due, so that task alone takes
     * no lead time; the two that sleep to the exact second take a tick's worth.
     */
    public function testOnlyTasksThatSleepTakeLeadTime(): void
    {
        $lookahead = static fn (string $task): int => (new \ReflectionClass($task))->getConstant('ENQUEUE_LOOKAHEAD');

        $this->assertSame(0, $lookahead(ScheduleMessages::class));
        $this->assertSame(ScheduleFunctions::ENQUEUE_TIMER, $lookahead(ScheduleFunctions::class));
        $this->assertSame(ScheduleExecutions::ENQUEUE_TIMER, $lookahead(ScheduleExecutions::class));
    }

    /**
     * @param array<string, mixed> $schedule
     */
    private function trigger(object $task, array $schedule): Trigger
    {
        $method = new \ReflectionMethod($task, 'trigger');

        return $method->invoke($task, $schedule);
    }

    /**
     * @return list<string>
     */
    private function dues(Trigger $trigger, string $from, string $until): array
    {
        return \array_map(
            static fn (\DateTimeImmutable $due): string => $due->format('H:i:s'),
            $trigger->occurrencesBetween(new \DateTimeImmutable($from), new \DateTimeImmutable($until)),
        );
    }
}
