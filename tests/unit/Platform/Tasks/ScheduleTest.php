<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Tasks;

use Appwrite\Event\Publisher\Func as FunctionPublisher;
use Appwrite\Event\Publisher\Maintenance as MaintenancePublisher;
use Appwrite\Event\Publisher\Messaging as MessagingPublisher;
use Appwrite\Platform\Tasks\Schedule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine as Co;
use Utopia\Database\Database;
use Utopia\Pools\Group;
use Utopia\Telemetry\Adapter\None as NoTelemetry;

final class ScheduleTest extends TestCase
{
    private const array INTERVALS = ['_APP_MAINTENANCE_INTERVAL', '_APP_MAINTENANCE_INACTIVE_INTERVAL'];

    /** @var array<string, string|false> */
    private array $intervals = [];

    protected function setUp(): void
    {
        foreach (self::INTERVALS as $name) {
            $this->intervals[$name] = \getenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->intervals as $name => $value) {
            \putenv($value === false ? $name : $name . '=' . $value);
        }
    }

    /**
     * @return \Iterator<string, array{string}>
     */
    public static function intervals(): \Iterator
    {
        yield 'active' => ['_APP_MAINTENANCE_INTERVAL'];
        yield 'inactive' => ['_APP_MAINTENANCE_INACTIVE_INTERVAL'];
    }

    #[DataProvider('intervals')]
    public function testInvalidMaintenanceIntervalStopsTheProcessBeforeAnyLoopStarts(string $name): void
    {
        \putenv($name . '=weekly');

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
