<?php

declare(strict_types=1);

namespace Tests\Unit\Maintenance;

use Appwrite\Maintenance\Cadence;
use Appwrite\Maintenance\Cohort;
use Appwrite\Maintenance\Slot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CadenceTest extends TestCase
{
    /**
     * @return \Iterator<string, array{int, int, int, int, int}>
     */
    public static function intervals(): \Iterator
    {
        yield 'daily active, weekly inactive' => [86400, 604800, 3600, 24, 168];
        yield 'hourly active' => [3600, 604800, 3600, 1, 168];
        yield 'ten minute active' => [600, 604800, 600, 1, 1008];
        yield 'active longer than an hour but not a whole number of them' => [5400, 604800, 3600, 1, 168];
        yield 'unset intervals' => [0, 0, 3600, 24, 168];
        yield 'negative intervals' => [-1, -1, 3600, 24, 168];
    }

    #[DataProvider('intervals')]
    public function testFromIntervals(int $active, int $inactive, int $tick, int $activeSlots, int $inactiveSlots): void
    {
        $cadence = Cadence::fromIntervals($active, $inactive);

        $this->assertSame($tick, $cadence->tick);
        $this->assertSame($activeSlots, $cadence->slots(Cohort::Active));
        $this->assertSame($inactiveSlots, $cadence->slots(Cohort::Inactive));
    }

    public function testAnchorIsTheStartTimeOnTheEpochDay(): void
    {
        $this->assertSame(0, Cadence::anchor('00:00', new \DateTimeZone('UTC')));
        $this->assertSame(12 * 3600 + 30 * 60, Cadence::anchor('12:30', new \DateTimeZone('UTC')));
        $this->assertSame(-19800, Cadence::anchor('00:00', new \DateTimeZone('Asia/Kolkata')));
    }

    public function testBootHoursBeforeTheStartTimeTicksOnTheNextHour(): void
    {
        $cadence = Cadence::fromIntervals(86400, 604800);
        $anchor = Cadence::anchor('12:00', new \DateTimeZone('UTC'));
        $now = $this->at('2026-03-02 05:30:00');

        $boundary = $cadence->next($now, null, $anchor);

        $this->assertSame($this->at('2026-03-02 06:00:00'), (float) $boundary);
        $this->assertSame(18, Slot::at($boundary, $anchor, $cadence->tick, $cadence->activeSlots)->index);
    }

    public function testBootAfterTheStartTimeTicksOnTheNextHour(): void
    {
        $cadence = Cadence::fromIntervals(86400, 604800);
        $anchor = Cadence::anchor('12:00', new \DateTimeZone('UTC'));

        $boundary = $cadence->next($this->at('2026-03-02 13:10:00'), null, $anchor);

        $this->assertSame($this->at('2026-03-02 14:00:00'), (float) $boundary);
        $this->assertSame(2, Slot::at($boundary, $anchor, $cadence->tick, $cadence->activeSlots)->index);
    }

    public function testStartTimeMinutesOffsetEveryTick(): void
    {
        $cadence = Cadence::fromIntervals(86400, 604800);
        $anchor = Cadence::anchor('00:30', new \DateTimeZone('UTC'));

        $this->assertSame($this->at('2026-03-02 06:30:00'), (float) $cadence->next($this->at('2026-03-02 05:31:00'), null, $anchor));
    }

    public function testBoundaryAtNowIsNotRunAgain(): void
    {
        $cadence = Cadence::fromIntervals(86400, 604800);
        $boundary = (int) $this->at('2026-03-02 14:00:00');

        $this->assertSame($boundary + 3600, $cadence->next((float) $boundary, $boundary, 0));
    }

    public function testClockSteppingBackwardsNeverRepeatsABoundary(): void
    {
        $cadence = Cadence::fromIntervals(86400, 604800);
        $last = (int) $this->at('2026-03-02 14:00:00');

        $this->assertSame($last + 3600, $cadence->next($last - 7200.0, $last, 0));
    }

    public function testOverrunTakesTheNextBoundaryAfterNow(): void
    {
        $cadence = Cadence::fromIntervals(86400, 604800);
        $last = (int) $this->at('2026-03-02 14:00:00');

        $this->assertSame($last + 3 * 3600, $cadence->next($last + 2 * 3600 + 600.0, $last, 0));
    }

    public function testActiveSlotZeroIsTheStartTimeEveryDay(): void
    {
        $cadence = Cadence::fromIntervals(86400, 604800);
        $anchor = Cadence::anchor('03:00', new \DateTimeZone('UTC'));

        foreach (['2026-03-02', '2026-03-03', '2027-11-19'] as $day) {
            $slot = Slot::at((int) $this->at($day . ' 03:00:00'), $anchor, $cadence->tick, $cadence->activeSlots);
            $this->assertSame(0, $slot->index, $day);
        }

        $inactive = [];
        for ($day = 0; $day < 7; $day++) {
            $at = (int) $this->at('2026-03-02 03:00:00') + $day * Cadence::DAY;
            $inactive[] = Slot::at($at, $anchor, $cadence->tick, $cadence->inactiveSlots)->index;
        }
        $this->assertContains(0, $inactive, 'inactive slot 0 lands on the start time once a week');
    }

    public function testSingleSlotIsReachedOnlyWhenABoundaryFallsInTheWindow(): void
    {
        $cadence = Cadence::fromIntervals(3600, 604800);
        $this->assertSame(1, $cadence->activeSlots);
        $hour = (int) $this->at('2026-03-02 15:00:00');

        $this->assertSame($hour, $cadence->reaches(0, 1, 0, $hour - 600, $hour + 300));
        $this->assertSame($hour, $cadence->reaches(0, 1, 0, $hour - 1, $hour));
        $this->assertNull($cadence->reaches(0, 1, 0, $hour, $hour + 1200), 'a boundary at the start of the window is excluded');
        $this->assertNull($cadence->reaches(0, 1, 0, $hour + 400, $hour + 1600));

        $reached = 0;
        for ($now = $hour; $now < $hour + Cadence::HOUR; $now++) {
            if ($cadence->reaches(0, 1, 0, $now - 301, $now + 900) !== null) {
                $reached++;
            }
        }
        $this->assertSame(1201, $reached, 'a window of 1201 seconds holds an hourly boundary for a third of the hour');
    }

    public function testManySlotsAreReachedOnlyByTheOwningBoundary(): void
    {
        $cadence = Cadence::fromIntervals(86400, 604800);
        $three = (int) $this->at('2026-03-02 03:00:00');

        $this->assertSame($three, $cadence->reaches(3, 24, 0, $three - 600, $three + 300));
        $this->assertNull($cadence->reaches(4, 24, 0, $three - 600, $three + 300));
        $this->assertSame($three + 3600, $cadence->reaches(4, 24, 0, $three - 600, $three + 3600));
        $this->assertNull($cadence->reaches(3, 24, 0, $three + 1, $three + 23 * 3600));
        $this->assertSame($three + 86400, $cadence->reaches(3, 24, 0, $three + 1, $three + 86400));
    }

    private function at(string $time): float
    {
        return (float) (new \DateTimeImmutable($time, new \DateTimeZone('UTC')))->getTimestamp();
    }
}
