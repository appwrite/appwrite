<?php

declare(strict_types=1);

namespace Utopia\Mqtt\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Mqtt\Keepalive;

final class KeepaliveTest extends TestCase
{
    public function testAScheduledConnectionIsReapedAtItsDeadline(): void
    {
        $wheel = new Keepalive(now: 1000);
        $wheel->schedule(7, 1005.0);

        $this->assertSame([], $wheel->drain(1004), 'not due before its deadline');
        $this->assertSame([7], $wheel->drain(1005), 'due at its deadline');
        $this->assertSame([], $wheel->drain(1006), 'reaped once, not returned again');
    }

    public function testAFractionalDeadlineIsNotReapedEarly(): void
    {
        // 1005.5 must round up: reaping at second 1005 would disconnect an active client
        // up to a second before its keep-alive deadline actually elapses.
        $wheel = new Keepalive(now: 1000);
        $wheel->schedule(7, 1005.5);

        $this->assertSame([], $wheel->drain(1005), 'not reaped before the 1005.5 deadline');
        $this->assertSame([7], $wheel->drain(1006), 'reaped once the deadline has passed');
    }

    public function testOneTickDrainsEveryElapsedSecond(): void
    {
        // Bucket resolution (1s) is finer than the tick, so a single coarse tick must drain
        // the whole elapsed range, never skipping a bucket between ticks.
        $wheel = new Keepalive(now: 1000);
        $wheel->schedule(1, 1003.0);
        $wheel->schedule(2, 1008.0);

        $this->assertSame([1, 2], $wheel->drain(1020));
    }

    public function testConnectionsSharingASecondAreAllReturned(): void
    {
        $wheel = new Keepalive(now: 1000);
        $wheel->schedule(1, 1002.0);
        $wheel->schedule(2, 1002.0);

        $this->assertSame([1, 2], $wheel->drain(1002));
    }

    public function testRemoveTakesAConnectionOffTheWheel(): void
    {
        $wheel = new Keepalive(now: 1000);
        $wheel->schedule(7, 1002.0);

        $wheel->remove(7);

        $this->assertSame([], $wheel->drain(1002));
    }

    public function testRemovingAnAlreadyDrainedConnectionIsHarmless(): void
    {
        $wheel = new Keepalive(now: 1000);
        $wheel->schedule(7, 1002.0);
        $this->assertSame([7], $wheel->drain(1002));

        $wheel->remove(7); // e.g. a close that races the reaper

        $this->assertSame([], $wheel->drain(1003));
    }

    public function testReschedulingMovesTheFdOffItsOldDeadline(): void
    {
        // A packet pushing the deadline forward must not leave the fd in its old bucket,
        // or the superseded deadline would reap a still-active client.
        $wheel = new Keepalive(now: 1000);
        $wheel->schedule(7, 1005.0);
        $wheel->schedule(7, 1010.0);

        $this->assertSame([], $wheel->drain(1005), 'not reaped at the superseded deadline');
        $this->assertSame([7], $wheel->drain(1010), 'reaped at the current deadline');
    }

    public function testAStaleDeadlineIsReapedOnTheNextTickNotDropped(): void
    {
        // A deadline already behind the cursor must not vanish: it resolves to the next tick.
        $wheel = new Keepalive(now: 1000);
        $wheel->schedule(7, 999.0);

        $this->assertSame([7], $wheel->drain(1001));
    }
}
