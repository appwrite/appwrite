<?php

namespace Appwrite\Schedule;

use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Schedule\Trigger;
use Utopia\Schedule\Trigger\Cron;
use Utopia\Schedule\Trigger\Shifted;

final class FunctionSchedule extends DatabaseSchedule
{
    /**
     * @param callable(Document): Database $getProjectDB
     * @param callable(Document, string, string): bool $isResourceBlocked
     * @param \Closure(array<string, mixed>): int $spread seconds to spread a
     *        due second's worth of functions over, per schedule
     */
    public function __construct(
        Database $dbForPlatform,
        mixed $getProjectDB,
        mixed $isResourceBlocked,
        private readonly \Closure $spread,
    ) {
        parent::__construct($dbForPlatform, $getProjectDB, $isResourceBlocked);
    }

    #[\Override]
    protected function type(): string
    {
        return SCHEDULE_RESOURCE_TYPE_FUNCTION;
    }

    #[\Override]
    protected function collection(): string
    {
        return RESOURCE_TYPE_FUNCTIONS;
    }

    /**
     * A cron expression, shifted so a fleet sharing one expression does not
     * share one second. The shift belongs to the schedule: the window covers
     * the shifted time and the watermark commits it.
     */
    #[\Override]
    protected function trigger(array $schedule): Trigger
    {
        $window = ($this->spread)($schedule);
        $resourceId = (string) $schedule['resourceId'];

        return new Shifted(
            new Cron((string) $schedule['schedule']),
            $window <= 1 ? 0 : \abs(\crc32($resourceId)) % $window,
        );
    }
}
