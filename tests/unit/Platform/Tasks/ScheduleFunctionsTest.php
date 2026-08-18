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
    public function testOccurrenceOnMinuteBoundarySurvivesWindowAnchor(): void
    {
        // A tick that starts milliseconds before a minute boundary crosses it
        // while the schedule loop is still running. Selection anchored on the
        // window start must still return the boundary occurrence — a lookup
        // anchored on the evaluation-time "now" would already be past it.
        $windowStart = new \DateTime('2026-08-17 15:59:59.941');
        $timeFrame = '2026-08-17 16:00:59.941';

        $occurrences = ScheduleFunctions::occurrencesWithin('*/15 * * * *', $windowStart, $timeFrame);

        $this->assertSame(
            ['2026-08-17 16:00:00'],
            \array_map(fn ($occurrence) => $occurrence->format('Y-m-d H:i:s'), $occurrences)
        );
    }

    public function testMissedOccurrencesAreBackfilled(): void
    {
        // The window start comes from the previous pass (persisted watermark),
        // so a restart gap yields every missed occurrence, oldest first.
        $windowStart = new \DateTime('2026-08-18 03:00:59.500');
        $timeFrame = '2026-08-18 03:31:00.500';

        $occurrences = ScheduleFunctions::occurrencesWithin('*/15 * * * *', $windowStart, $timeFrame);

        $this->assertSame(
            ['2026-08-18 03:15:00', '2026-08-18 03:30:00'],
            \array_map(fn ($occurrence) => $occurrence->format('Y-m-d H:i:s'), $occurrences)
        );
    }

    public function testEmptyWindowAndInvalidCronYieldNothing(): void
    {
        $windowStart = new \DateTime('2026-08-18 03:01:00.000');

        $this->assertSame([], ScheduleFunctions::occurrencesWithin('*/15 * * * *', $windowStart, '2026-08-18 03:10:00.000'));
        $this->assertSame([], ScheduleFunctions::occurrencesWithin('not a cron', $windowStart, '2026-08-18 04:00:00.000'));
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
}
