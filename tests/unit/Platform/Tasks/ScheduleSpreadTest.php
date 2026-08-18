<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Tasks;

use Appwrite\Platform\Tasks\ScheduleFunctions;
use PHPUnit\Framework\TestCase;

final class ScheduleSpreadTest extends TestCase
{
    /**
     * A resource keeps its slot across deploys, which is the point of
     * deriving it from the id. Changing how the offset is derived moves every
     * schedule at once — the burst this spread exists to prevent — so the
     * values are pinned rather than compared to themselves.
     */
    public function testSpreadOffsetIsStableForAnId(): void
    {
        $this->assertSame(33, ScheduleFunctions::spreadOffset('64f5c8e2a1b3d4e5f6a7b8c9', 60));
        $this->assertSame(213, ScheduleFunctions::spreadOffset('64f5c8e2a1b3d4e5f6a7b8c9', 300));
        $this->assertSame(16, ScheduleFunctions::spreadOffset('hourly-report', 60));
        $this->assertSame(24, ScheduleFunctions::spreadOffset('nightly-backup', 60));
    }

    public function testSpreadOffsetIsBounded(): void
    {
        foreach ($this->sampleIds() as $id) {
            foreach ([2, 30, 60, 300] as $window) {
                $offset = ScheduleFunctions::spreadOffset($id, $window);
                $this->assertGreaterThanOrEqual(0, $offset);
                $this->assertLessThan($window, $offset);
            }
        }
    }

    public function testSpreadOffsetIsZeroWhenDisabled(): void
    {
        $this->assertSame(0, ScheduleFunctions::spreadOffset('64f5c8e2a1b3d4e5f6a7b8c9', 1));
        $this->assertSame(0, ScheduleFunctions::spreadOffset('64f5c8e2a1b3d4e5f6a7b8c9', 0));
        $this->assertSame(0, ScheduleFunctions::spreadOffset('64f5c8e2a1b3d4e5f6a7b8c9', -5));
    }

    public function testSpreadOffsetDispersesDistinctResources(): void
    {
        $offsets = [];
        foreach ($this->sampleIds() as $id) {
            $offsets[] = ScheduleFunctions::spreadOffset($id, 60);
        }

        // Resources sharing a cron slot must not all land in the same second.
        $this->assertGreaterThan(10, count(array_unique($offsets)));
    }

    /**
     * @return string[]
     */
    private function sampleIds(): array
    {
        $ids = [];
        for ($i = 0; $i < 50; $i++) {
            $ids[] = md5("resource-{$i}");
        }

        return $ids;
    }
}
