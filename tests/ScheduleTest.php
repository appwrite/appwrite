<?php

declare(strict_types=1);

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Schedule\Trigger\At;
use Utopia\Schedule\Trigger\Cron;
use Utopia\Schedule\Trigger\Interval;
use Utopia\Schedule\Trigger\Shifted;

final class ScheduleTest extends TestCase
{
    public function testCronOccurrenceOnWindowStartBelongsToTheWindow(): void
    {
        $cron = new Cron('*/15 * * * *');

        $this->assertSame(
            ['16:00:00'],
            $this->format($cron->occurrencesBetween(
                new \DateTimeImmutable('2026-08-17 16:00:00.000000'),
                new \DateTimeImmutable('2026-08-17 16:01:00.000000'),
            )),
        );
    }

    public function testCronOccurrenceOnWindowEndBelongsToTheNextWindow(): void
    {
        $cron = new Cron('*/15 * * * *');

        $this->assertSame([], $cron->occurrencesBetween(
            new \DateTimeImmutable('2026-08-17 15:45:00.000001'),
            new \DateTimeImmutable('2026-08-17 16:00:00.000000'),
        ));
    }

    public function testCronBoundaryOccurrenceSurvivesSubSecondWindowStart(): void
    {
        // The window opens 59 milliseconds before a minute boundary — the
        // exact shape of a tick racing the wall clock.
        $cron = new Cron('*/15 * * * *');

        $this->assertSame(
            ['16:00:00'],
            $this->format($cron->occurrencesBetween(
                new \DateTimeImmutable('2026-08-17 15:59:59.941000'),
                new \DateTimeImmutable('2026-08-17 16:00:59.941000'),
            )),
        );
    }

    public function testCronEnumeratesEveryOccurrenceInAWideWindow(): void
    {
        $cron = new Cron('*/15 * * * *');

        $this->assertSame(
            ['03:15:00', '03:30:00'],
            $this->format($cron->occurrencesBetween(
                new \DateTimeImmutable('2026-08-18 03:00:59.500000'),
                new \DateTimeImmutable('2026-08-18 03:31:00.500000'),
            )),
        );
    }

    public function testCronRejectsInvalidExpressions(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Cron('not a cron');
    }

    public function testCronRejectsImpossibleExpressions(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Cron('0 0 31 2 *');
    }

    public function testIntervalOccurrencesSitOnTheEpochGrid(): void
    {
        $interval = new Interval(900);

        $this->assertSame(
            ['16:15:00', '16:30:00'],
            $this->format($interval->occurrencesBetween(
                new \DateTimeImmutable('2026-08-17 16:07:00.000000'),
                new \DateTimeImmutable('2026-08-17 16:35:00.000000'),
            )),
        );
    }

    public function testIntervalAnchorSetsThePhase(): void
    {
        $interval = new Interval(900, new \DateTimeImmutable('2026-08-17 16:07:30.000000'));

        $this->assertSame(
            ['16:22:30', '16:37:30'],
            $this->format($interval->occurrencesBetween(
                new \DateTimeImmutable('2026-08-17 16:08:00.000000'),
                new \DateTimeImmutable('2026-08-17 16:40:00.000000'),
            )),
        );
    }

    public function testIntervalOccurrenceOnWindowStartBelongsToTheWindow(): void
    {
        $interval = new Interval(60);

        $occurrences = $interval->occurrencesBetween(
            new \DateTimeImmutable('2026-08-17 16:07:00.000000'),
            new \DateTimeImmutable('2026-08-17 16:07:30.000000'),
        );

        $this->assertSame(['16:07:00'], $this->format($occurrences));
    }

    public function testIntervalRejectsSubSecondCadence(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Interval(0);
    }

    public function testCronListsRangesAndSteps(): void
    {
        $cron = new Cron('15,45-50/2 8 * * *');

        $this->assertSame(
            ['08:15:00', '08:45:00', '08:47:00', '08:49:00'],
            $this->format($cron->occurrencesBetween(
                new \DateTimeImmutable('2026-08-17 00:00:00.000000'),
                new \DateTimeImmutable('2026-08-18 00:00:00.000000'),
            )),
        );
    }

    public function testCronMonthAndDayNames(): void
    {
        // 2026-08-17 is a Monday.
        $cron = new Cron('30 9 * AUG mon-Fri');

        $occurrences = $cron->occurrencesBetween(
            new \DateTimeImmutable('2026-08-14 12:00:00.000000'), // Friday noon
            new \DateTimeImmutable('2026-08-18 00:00:00.000000'),
        );

        $this->assertSame(
            ['2026-08-17 09:30:00'],
            array_map(fn(\DateTimeImmutable $occurrence): string => $occurrence->format('Y-m-d H:i:s'), $occurrences),
        );
    }

    public function testCronSevenMeansSunday(): void
    {
        $sundayViaSeven = new Cron('0 0 * * 7');
        $sundayViaZero = new Cron('0 0 * * 0');

        $start = new \DateTimeImmutable('2026-08-17 00:00:01.000000');
        $end = new \DateTimeImmutable('2026-08-31 00:00:00.000000');

        $this->assertEquals($sundayViaZero->occurrencesBetween($start, $end), $sundayViaSeven->occurrencesBetween($start, $end));
        $this->assertSame(
            ['2026-08-23', '2026-08-30'],
            array_map(fn(\DateTimeImmutable $occurrence): string => $occurrence->format('Y-m-d'), $sundayViaSeven->occurrencesBetween($start, $end)),
        );
    }

    public function testCronMacros(): void
    {
        $daily = new Cron('@daily');

        $this->assertSame(
            ['2026-08-18 00:00:00'],
            array_map(fn(\DateTimeImmutable $occurrence): string => $occurrence->format('Y-m-d H:i:s'), $daily->occurrencesBetween(
                new \DateTimeImmutable('2026-08-17 00:00:01.000000'),
                new \DateTimeImmutable('2026-08-19 00:00:00.000000'),
            )),
        );
    }

    public function testCronRestrictedDayFieldsCombineWithOr(): void
    {
        // Vixie cron: "the 13th, or any Friday" — not "Friday the 13th".
        $cron = new Cron('0 0 13 * 5');

        $this->assertSame(
            ['2026-08-07', '2026-08-13', '2026-08-14'],
            array_map(fn(\DateTimeImmutable $occurrence): string => $occurrence->format('Y-m-d'), $cron->occurrencesBetween(
                new \DateTimeImmutable('2026-08-01 00:00:01.000000'),
                new \DateTimeImmutable('2026-08-15 00:00:00.000000'),
            )),
        );
    }

    public function testCronLeapDayFiresOnLeapYearsOnly(): void
    {
        $cron = new Cron('0 12 29 2 *');

        $this->assertSame(
            ['2028-02-29 12:00:00'],
            array_map(fn(\DateTimeImmutable $occurrence): string => $occurrence->format('Y-m-d H:i:s'), $cron->occurrencesBetween(
                new \DateTimeImmutable('2026-03-01 00:00:00.000000'),
                new \DateTimeImmutable('2029-01-01 00:00:00.000000'),
            )),
        );
    }

    /**
     * @return \Iterator<int, array{string, list<string>}>
     */
    public static function quartzCronProvider(): \Iterator
    {
        yield ['0 0 L * *', ['2026-01-31', '2026-02-28', '2026-03-31']];
        yield ['0 0 L-2 * *', ['2026-01-29', '2026-02-26', '2026-03-29']];
        yield ['0 0 LW * *', ['2026-01-30', '2026-02-27', '2026-03-31']];
        // the 31st is a Tuesday, the other two months end on a weekend
        yield ['0 0 15W * *', ['2026-01-15', '2026-02-16', '2026-03-16']];
        // the 15th falls on a Sunday in February and March
        yield ['0 0 1W * *', ['2026-01-01', '2026-02-02', '2026-03-02']];
        // never steps back into the previous month
        yield ['0 0 ? * 5L', ['2026-01-30', '2026-02-27', '2026-03-27']];
        yield ['0 0 ? * 7L', ['2026-01-25', '2026-02-22', '2026-03-29']];
        // 7 is Sunday here, not Quartz's Saturday
        yield ['0 0 ? * FRI#3', ['2026-01-16', '2026-02-20', '2026-03-20']];
        yield ['0 0 1,L * ?', ['2026-01-01', '2026-01-31', '2026-02-01', '2026-02-28', '2026-03-01', '2026-03-31']];
    }

    /**
     * @param list<string> $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('quartzCronProvider')]
    public function testCronQuartzDayExtensions(string $expression, array $expected): void
    {
        $cron = new Cron($expression);

        $this->assertSame(
            $expected,
            array_map(fn(\DateTimeImmutable $occurrence): string => $occurrence->format('Y-m-d'), $cron->occurrencesBetween(
                new \DateTimeImmutable('2026-01-01 00:00:00.000000'),
                new \DateTimeImmutable('2026-04-01 00:00:00.000000'),
            )),
        );
    }

    public function testCronQuestionMarkReadsAsAnyDay(): void
    {
        $questionMark = new Cron('0 0 ? * *');
        $star = new Cron('0 0 * * *');

        $window = [new \DateTimeImmutable('2026-01-01 00:00:00.000000'), new \DateTimeImmutable('2026-01-08 00:00:00.000000')];

        $this->assertEquals(
            $star->occurrencesBetween(...$window),
            $questionMark->occurrencesBetween(...$window),
        );
    }

    /**
     * @return \Iterator<int, array{string}>
     */
    public static function malformedCronProvider(): \Iterator
    {
        yield ['not a cron'];
        yield ['* * * *'];
        // four fields
        yield ['* * * * * *'];
        // six fields
        yield ['61 * * * *'];
        // out of range
        yield ['* 24 * * *'];
        // out of range
        yield ['*/0 * * * *'];
        // zero step
        yield ['5/10 * * * *'];
        // steps need * or a range
        yield ['5-1 * * * *'];
        // reversed range
        yield ['* * L-31 * *'];
        // day-of-month offset past any month
        yield ['* * 32W * *'];
        // weekday nearest a day that cannot exist
        yield ['* * * * 1#6'];
        // there is no sixth Monday
        yield ['* * * * L'];
        // bare L means nothing in day of week
        yield ['0 0 31 2 *'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedCronProvider')]
    public function testCronRejectsMalformedExpressions(string $expression): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Cron($expression);
    }

    public function testWindowsTileAtEverySubSecondSplitPhase(): void
    {
        // Two adjacent windows split anywhere inside the two seconds
        // around a slot select the boundary occurrence exactly once —
        // the property that makes tick phase irrelevant.
        $cron = new Cron('*/5 * * * *');
        $before = new \DateTimeImmutable('2026-08-17 15:59:00.000000');
        $after = new \DateTimeImmutable('2026-08-17 16:01:00.000000');
        $boundary = new \DateTimeImmutable('2026-08-17 16:00:00.000000');

        for ($step = 0; $step <= 54; ++$step) {
            $split = $before->modify('+59 seconds')->modify(\sprintf('+%d milliseconds', $step * 37));

            $count = 0;
            foreach ([...$cron->occurrencesBetween($before, $split), ...$cron->occurrencesBetween($split, $after)] as $occurrence) {
                if ($occurrence == $boundary) {
                    ++$count;
                }
            }

            $this->assertSame(1, $count, "split at {$split->format('H:i:s.u')} must select 16:00:00 exactly once");
        }
    }

    public function testCronSkipsNonexistentLocalTimes(): void
    {
        // America/New_York springs forward on 2026-03-08: 02:30 does not
        // exist that day. The run is skipped, not doubled or crashed —
        // nonexistent local times follow PHP's date normalization.
        $timezone = new \DateTimeZone('America/New_York');
        $cron = new Cron('30 2 * * *');

        $occurrences = $cron->occurrencesBetween(
            new \DateTimeImmutable('2026-03-07 12:00:00', $timezone),
            new \DateTimeImmutable('2026-03-09 12:00:00', $timezone),
        );

        $this->assertSame(
            ['2026-03-09 02:30:00 EDT'],
            array_map(fn(\DateTimeImmutable $occurrence): string => $occurrence->format('Y-m-d H:i:s T'), $occurrences),
        );
    }

    public function testCronRepeatedLocalTimeFiresOnce(): void
    {
        // America/New_York falls back on 2026-11-01: wall-clock 01:30
        // happens twice. The schedule fires once, on the first pass.
        $timezone = new \DateTimeZone('America/New_York');
        $cron = new Cron('30 1 * * *');

        $occurrences = $cron->occurrencesBetween(
            new \DateTimeImmutable('2026-10-31 12:00:00', $timezone),
            new \DateTimeImmutable('2026-11-01 12:00:00', $timezone),
        );

        $this->assertSame(
            ['2026-11-01 01:30:00 EDT'],
            array_map(fn(\DateTimeImmutable $occurrence): string => $occurrence->format('Y-m-d H:i:s T'), $occurrences),
        );
    }

    public function testAtFiresInsideItsWindowOnly(): void
    {
        $at = new At(new \DateTimeImmutable('2026-08-17 16:00:30.000000'));

        $this->assertSame(['16:00:30'], $this->format($at->occurrencesBetween(
            new \DateTimeImmutable('2026-08-17 16:00:00.000000'),
            new \DateTimeImmutable('2026-08-17 16:01:00.000000'),
        )));

        $this->assertSame([], $at->occurrencesBetween(
            new \DateTimeImmutable('2026-08-17 16:01:00.000000'),
            new \DateTimeImmutable('2026-08-17 16:02:00.000000'),
        ));
    }

    public function testInIsAnchoredToItsCreationTime(): void
    {
        $at = At::in(30, new \DateTimeImmutable('2026-08-17 16:00:00.000000'));

        $this->assertSame(['16:00:30'], $this->format($at->occurrencesBetween(
            new \DateTimeImmutable('2026-08-17 16:00:29.000000'),
            new \DateTimeImmutable('2026-08-17 16:00:31.000000'),
        )));
    }

    public function testShiftedMovesEveryOccurrence(): void
    {
        $shifted = new Shifted(new Cron('*/15 * * * *'), 40);

        $this->assertSame(['03:00:40', '03:15:40'], $this->format($shifted->occurrencesBetween(
            new \DateTimeImmutable('2026-08-18 03:00:00.000000'),
            new \DateTimeImmutable('2026-08-18 03:16:00.000000'),
        )));
    }

    /**
     * The window is shifted back and the results forward, so a shifted
     * occurrence still belongs to exactly one window. Getting this backwards
     * drops runs on one side of every boundary and duplicates them on the
     * other, which no fleet notices until it is a support ticket.
     */
    public function testShiftedOccurrencesStillTileAcrossConsecutiveWindows(): void
    {
        $shifted = new Shifted(new Cron('* * * * *'), 30);
        $seen = [];

        // Boundaries that fall between the true minute and the shifted one.
        foreach (['03:00:15', '03:01:15', '03:02:15'] as $index => $edge) {
            $seen = [...$seen, ...$this->format($shifted->occurrencesBetween(
                new \DateTimeImmutable("2026-08-18 {$edge}.000000"),
                new \DateTimeImmutable('2026-08-18 ' . ['03:01:15', '03:02:15', '03:03:15'][$index] . '.000000'),
            ))];
        }

        $this->assertSame(['03:00:30', '03:01:30', '03:02:30'], $seen);
    }

    public function testShiftedKeepsTheKindOfScheduleItWraps(): void
    {
        $this->assertTrue((new Shifted(new Cron('* * * * *'), 5))->recurring());
        $this->assertFalse((new Shifted(new At(new \DateTimeImmutable('2026-08-18 03:00:00')), 5))->recurring());
    }

    public function testShiftedByNothingChangesNothing(): void
    {
        $window = [new \DateTimeImmutable('2026-08-18 03:00:00.000000'), new \DateTimeImmutable('2026-08-18 03:02:00.000000')];
        $cron = new Cron('* * * * *');

        $this->assertSame(
            $this->format($cron->occurrencesBetween(...$window)),
            $this->format((new Shifted($cron, 0))->occurrencesBetween(...$window)),
        );
    }

    public function testShiftedAcceptsANegativeShift(): void
    {
        $shifted = new Shifted(new Cron('0 * * * *'), -10);

        $this->assertSame(['02:59:50'], $this->format($shifted->occurrencesBetween(
            new \DateTimeImmutable('2026-08-18 02:59:00.000000'),
            new \DateTimeImmutable('2026-08-18 03:00:00.000000'),
        )));
    }

    /**
     * @param list<\DateTimeImmutable> $occurrences
     * @return list<string>
     */
    private function format(array $occurrences): array
    {
        return array_map(fn(\DateTimeImmutable $occurrence): string => $occurrence->format('H:i:s'), $occurrences);
    }
}
