<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Tasks;

use Appwrite\Platform\Tasks\ScheduleBase;
use PHPUnit\Framework\TestCase;

final class ScheduleBaseTest extends TestCase
{
    public function testSpreadOffsetIsDeterministic(): void
    {
        $this->assertSame(
            ScheduleBase::spreadOffset('64f5c8e2a1b3d4e5f6a7b8c9', 60),
            ScheduleBase::spreadOffset('64f5c8e2a1b3d4e5f6a7b8c9', 60)
        );
    }

    public function testSpreadOffsetIsBounded(): void
    {
        foreach ($this->sampleIds() as $id) {
            foreach ([2, 30, 60, 300] as $window) {
                $offset = ScheduleBase::spreadOffset($id, $window);
                $this->assertGreaterThanOrEqual(0, $offset);
                $this->assertLessThan($window, $offset);
            }
        }
    }

    public function testSpreadOffsetIsZeroWhenDisabled(): void
    {
        $this->assertSame(0, ScheduleBase::spreadOffset('64f5c8e2a1b3d4e5f6a7b8c9', 1));
        $this->assertSame(0, ScheduleBase::spreadOffset('64f5c8e2a1b3d4e5f6a7b8c9', 0));
        $this->assertSame(0, ScheduleBase::spreadOffset('64f5c8e2a1b3d4e5f6a7b8c9', -5));
    }

    public function testSpreadOffsetDispersesDistinctResources(): void
    {
        $offsets = [];
        foreach ($this->sampleIds() as $id) {
            $offsets[] = ScheduleBase::spreadOffset($id, 60);
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
