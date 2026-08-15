<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Tasks;

use Appwrite\Platform\Services\Tasks;
use Appwrite\Platform\Tasks\Schedule;
use Appwrite\Platform\Tasks\ScheduleExecutions;
use Appwrite\Platform\Tasks\ScheduleFunctions;
use Appwrite\Platform\Tasks\ScheduleMessages;
use PHPUnit\Framework\TestCase;

final class ScheduleTest extends TestCase
{
    public function testCombinedScheduleIsRegisteredAlongsideSeparateTasks(): void
    {
        $service = new Tasks();

        $this->assertInstanceOf(Schedule::class, $service->getAction('schedule'));
        $this->assertInstanceOf(ScheduleFunctions::class, $service->getAction('schedule-functions'));
        $this->assertInstanceOf(ScheduleExecutions::class, $service->getAction('schedule-executions'));
        $this->assertInstanceOf(ScheduleMessages::class, $service->getAction('schedule-messages'));
    }

    public function testScheduleBaseExposesBootstrapHooksForCombinedMode(): void
    {
        $task = new ScheduleFunctions();

        $this->assertTrue(\method_exists($task, 'setup'));
        $this->assertTrue(\method_exists($task, 'start'));
        $this->assertTrue(\method_exists($task, 'listen'));
        $this->assertTrue(\method_exists($task, 'getSchedules'));
        $this->assertSame([], $task->getSchedules());
    }
}
