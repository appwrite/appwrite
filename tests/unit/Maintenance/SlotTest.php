<?php

declare(strict_types=1);

namespace Tests\Unit\Maintenance;

use Appwrite\Maintenance\Cadence;
use Appwrite\Maintenance\Slot;
use PHPUnit\Framework\TestCase;

final class SlotTest extends TestCase
{
    private const int ANCHOR = 1_700_000_000;

    public function testTickAtTheAnchorIsTheFirstSlotOfTheFirstCycle(): void
    {
        $slot = Slot::at(self::ANCHOR, self::ANCHOR, Cadence::HOUR, 24);

        $this->assertSame(0, $slot->index);
        $this->assertSame(24, $slot->count);
        $this->assertSame(0, $slot->cycle);
    }

    public function testLastHourOfTheDayIsTheLastSlot(): void
    {
        $slot = Slot::at(self::ANCHOR + 23 * Cadence::HOUR, self::ANCHOR, Cadence::HOUR, 24);

        $this->assertSame(23, $slot->index);
        $this->assertSame(0, $slot->cycle);

        $next = Slot::at(self::ANCHOR + 24 * Cadence::HOUR + 59, self::ANCHOR, Cadence::HOUR, 24);

        $this->assertSame(0, $next->index);
        $this->assertSame(1, $next->cycle);
    }

    public function testTickBeforeTheAnchorWrapsToTheEndOfThePreviousCycle(): void
    {
        $slot = Slot::at(self::ANCHOR - Cadence::HOUR, self::ANCHOR, Cadence::HOUR, 24);

        $this->assertSame(23, $slot->index);
        $this->assertSame(-1, $slot->cycle);

        $partial = Slot::at(self::ANCHOR - 1, self::ANCHOR, Cadence::HOUR, 24);

        $this->assertSame(23, $partial->index);
        $this->assertSame(-1, $partial->cycle);

        $earlier = Slot::at(self::ANCHOR - 25 * Cadence::HOUR, self::ANCHOR, Cadence::HOUR, 24);

        $this->assertSame(23, $earlier->index);
        $this->assertSame(-2, $earlier->cycle);
    }
}
