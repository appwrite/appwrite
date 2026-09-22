<?php

namespace Appwrite\Schedule\Source;

use Utopia\Database\Document;
use Utopia\Schedule\Trigger;
use Utopia\Schedule\Trigger\Cron;
use Utopia\Schedule\Trigger\Shifted;

final class Functions extends Database
{
    /**
     * @param callable(Document): \Utopia\Database\Database $getProjectDB
     * @param callable(Document, string, string): bool $isResourceBlocked
     * @param \Closure(array<string, mixed>): int $spread seconds to spread a
     */
    public function __construct(
        \Utopia\Database\Database $dbForPlatform,
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

    #[\Override]
    protected function resource(\Utopia\Database\Database $projectDB, array $schedule): Document
    {
        // The functions worker reads variables fresh at execution time, so the
        // snapshot must not carry them: they would go stale in memory.
        return $projectDB->skipFilters(
            fn () => $projectDB->getDocument($this->collection(), $schedule['resourceId']),
            APP_FUNCTIONS_SUBQUERIES
        );
    }

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
