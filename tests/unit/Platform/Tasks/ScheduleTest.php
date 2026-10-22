<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Tasks;

use Appwrite\Event\Publisher\Func as FunctionPublisher;
use Appwrite\Event\Publisher\Maintenance as MaintenancePublisher;
use Appwrite\Event\Publisher\Messaging as MessagingPublisher;
use Appwrite\Platform\Tasks\Schedule;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine as Co;
use Utopia\Database\Database;
use Utopia\Pools\Group;
use Utopia\Telemetry\Adapter\None as NoTelemetry;

final class ScheduleTest extends TestCase
{
    private string|false $interval;

    protected function setUp(): void
    {
        $this->interval = \getenv('_APP_MAINTENANCE_INACTIVE_INTERVAL');
    }

    protected function tearDown(): void
    {
        \putenv($this->interval === false ? '_APP_MAINTENANCE_INACTIVE_INTERVAL' : '_APP_MAINTENANCE_INACTIVE_INTERVAL=' . $this->interval);
    }

    public function testInvalidInactiveIntervalStopsTheProcessBeforeAnyLoopStarts(): void
    {
        \putenv('_APP_MAINTENANCE_INACTIVE_INTERVAL=weekly');

        $refused = null;

        try {
            new Schedule()->action(
                $this->createStub(FunctionPublisher::class),
                $this->createStub(MessagingPublisher::class),
                fn (): bool => false,
                $this->createStub(Database::class),
                fn (): Database => $this->createStub(Database::class),
                new NoTelemetry(),
                fn (): array => [],
                $this->createStub(MaintenancePublisher::class),
                $this->createStub(Group::class),
            );
        } catch (\InvalidArgumentException $error) {
            $refused = $error;
        }

        // The throw reaches the entrypoint, so the container exits instead of running the other schedulers without maintenance.
        $this->assertInstanceOf(\InvalidArgumentException::class, $refused);
        $this->assertSame(0, Co::stats()['coroutine_num']);
    }
}
