<?php

declare(strict_types=1);

namespace Tests\Unit\Maintenance;

use Appwrite\Maintenance\Cadence;
use Appwrite\Maintenance\Slot;
use Appwrite\Maintenance\Ticker;
use PHPUnit\Framework\TestCase;
use Utopia\Schedule\Clock\Test as TestClock;

final class TickerTest extends TestCase
{
    public function testWakesAtTheBoundaryItReturns(): void
    {
        $clock = $this->clock('2026-03-02 05:30:00');
        $ticker = new Ticker(Cadence::fromIntervals(86400, 604800), Cadence::anchor('12:00', new \DateTimeZone('UTC')), $clock);

        $tick = $ticker->next();

        $this->assertSame($this->at('2026-03-02 06:00:00'), $tick->boundary);
        $this->assertSame(0, $tick->skipped);
        $this->assertSame($tick->boundary, $clock->now()->getTimestamp());
    }

    public function testClockSteppingBackwardsWaitsForTheNextBoundaryInRealTime(): void
    {
        $clock = $this->clock('2026-03-02 13:59:59');
        $ticker = new Ticker(Cadence::fromIntervals(86400, 604800), 0, $clock);
        $first = $ticker->next();

        $clock->advance(-2 * Cadence::HOUR);
        $second = $ticker->next();

        $this->assertSame($first->boundary + Cadence::HOUR, $second->boundary);
        $this->assertSame(0, $second->skipped);
        $this->assertSame($second->boundary, $clock->now()->getTimestamp(), 'the tick does not run ahead of the clock');
    }

    public function testOverrunSkipsTheMissedBoundaries(): void
    {
        $clock = $this->clock('2026-03-02 13:59:59');
        $ticker = new Ticker(Cadence::fromIntervals(86400, 604800), 0, $clock);
        $first = $ticker->next();

        $clock->advance(2 * Cadence::HOUR + 600);
        $second = $ticker->next();

        $this->assertSame($first->boundary + 3 * Cadence::HOUR, $second->boundary);
        $this->assertSame(2, $second->skipped);
    }

    public function testBootInstantDoesNotChangeTheSlotAtAWallClockTime(): void
    {
        $cadence = Cadence::fromIntervals(86400, 604800);
        $anchor = Cadence::anchor('00:00', new \DateTimeZone('UTC'));
        $target = $this->at('2026-03-09 15:00:00');

        $slots = [];
        foreach (['2026-03-02 05:30:00', '2026-03-05 23:59:30'] as $boot) {
            $ticker = new Ticker($cadence, $anchor, $this->clock($boot));
            do {
                $tick = $ticker->next();
            } while ($tick->boundary < $target);

            $this->assertSame($target, $tick->boundary, $boot);
            $active = Slot::at($tick->boundary, $anchor, $cadence->tick, $cadence->activeSlots);
            $inactive = Slot::at($tick->boundary, $anchor, $cadence->tick, $cadence->inactiveSlots);
            $slots[] = [$active->index, $active->cycle, $inactive->index, $inactive->cycle];
        }

        $this->assertSame($slots[0], $slots[1]);
    }

    private function clock(string $time): TestClock
    {
        return new TestClock(new \DateTimeImmutable($time, new \DateTimeZone('UTC')));
    }

    private function at(string $time): int
    {
        return (new \DateTimeImmutable($time, new \DateTimeZone('UTC')))->getTimestamp();
    }
}
