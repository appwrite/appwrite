<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Tasks;

use Appwrite\Platform\Tasks\ScheduleFunctions;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Database;

final class ScheduleFunctionsTest extends TestCase
{
    public function testSpreadOffsetIsDeterministic(): void
    {
        $this->assertSame(
            ScheduleFunctions::spreadOffset('64f5c8e2a1b3d4e5f6a7b8c9', 60),
            ScheduleFunctions::spreadOffset('64f5c8e2a1b3d4e5f6a7b8c9', 60)
        );
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

    public function testSpreadOffsetDispersesDistinctFunctions(): void
    {
        $offsets = [];
        foreach ($this->sampleIds() as $id) {
            $offsets[] = ScheduleFunctions::spreadOffset($id, 60);
        }

        // The whole point: functions sharing a cron slot must not all land
        // in the same second. Expect real dispersion across the window.
        $this->assertGreaterThan(10, count(array_unique($offsets)));
    }

    public function testSpreadWindowDefaultsToEnv(): void
    {
        $task = new class () extends ScheduleFunctions {
            public function window(array $schedule, Database $dbForPlatform): int
            {
                return $this->spreadWindow($schedule, $dbForPlatform);
            }
        };
        $dbForPlatform = new Database(new Memory(), new Cache(new NoCache()));

        $previous = getenv('_APP_FUNCTIONS_SCHEDULE_SPREAD');
        try {
            putenv('_APP_FUNCTIONS_SCHEDULE_SPREAD=45');
            $this->assertSame(45, $task->window([], $dbForPlatform));

            putenv('_APP_FUNCTIONS_SCHEDULE_SPREAD');
            $this->assertSame(0, $task->window([], $dbForPlatform));
        } finally {
            $previous === false
                ? putenv('_APP_FUNCTIONS_SCHEDULE_SPREAD')
                : putenv("_APP_FUNCTIONS_SCHEDULE_SPREAD={$previous}");
        }
    }

    /**
     * @return string[]
     */
    private function sampleIds(): array
    {
        $ids = [];
        for ($i = 0; $i < 50; $i++) {
            $ids[] = md5("function-{$i}");
        }

        return $ids;
    }
}
