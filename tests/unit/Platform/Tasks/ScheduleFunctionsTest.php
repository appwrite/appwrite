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
