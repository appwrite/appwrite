<?php

declare(strict_types=1);

namespace Tests\Unit\Appwrite\Mqtt;

use Appwrite\Mqtt\KeepAlive;
use PHPUnit\Framework\TestCase;

final class KeepAliveTest extends TestCase
{
    public function testScheduleReturnsTheDeadlineSlotAndDrainReturnsIt(): void
    {
        $wheel = new KeepAlive(1000);

        $this->assertSame(1005, $wheel->schedule(7, 1005.5)); // floors to second 1005
        $this->assertSame([], $wheel->drain(1004), 'not due before its second');
        $this->assertSame([7], $wheel->drain(1005), 'due at its second');
        $this->assertSame([], $wheel->drain(1006), 'drained, not returned again');
    }

    public function testOneTickDrainsEveryElapsedSecond(): void
    {
        // Bucket resolution (1s) is finer than the tick, so a single coarse tick must drain
        // the whole elapsed range, never skipping a bucket between ticks.
        $wheel = new KeepAlive(1000);
        $wheel->schedule(1, 1003.0);
        $wheel->schedule(2, 1007.9);

        $this->assertSame([1, 2], $wheel->drain(1020));
    }

    public function testConnectionsSharingASecondAreAllReturned(): void
    {
        $wheel = new KeepAlive(1000);
        $wheel->schedule(1, 1002.1);
        $wheel->schedule(2, 1002.9);

        $this->assertSame([1, 2], $wheel->drain(1002));
    }

    public function testRemoveTakesAConnectionOffItsSlot(): void
    {
        $wheel = new KeepAlive(1000);
        $slot = $wheel->schedule(7, 1002.0);

        $wheel->remove(7, $slot);

        $this->assertSame([], $wheel->drain(1002));
    }

    public function testRemovingAnAlreadyDrainedSlotIsHarmless(): void
    {
        $wheel = new KeepAlive(1000);
        $slot = $wheel->schedule(7, 1002.0);
        $this->assertSame([7], $wheel->drain(1002));

        $wheel->remove(7, $slot); // e.g. a close that races the reaper

        $this->assertSame([], $wheel->drain(1003));
    }

    public function testADeadlineAlreadyPastLandsInTheNextTick(): void
    {
        // Never drop a connection into an already-drained second: it must resolve to cursor + 1.
        $wheel = new KeepAlive(1000);

        $this->assertSame(1001, $wheel->schedule(7, 999.0));
        $this->assertSame([7], $wheel->drain(1001));
    }

    public function testDefaults(): void
    {
        $this->assertSame(20, KeepAlive::INTERVAL);
        $this->assertEqualsWithDelta(1.5, KeepAlive::MULTIPLIER, PHP_FLOAT_EPSILON);
    }
}
