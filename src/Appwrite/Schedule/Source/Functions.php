<?php

namespace Appwrite\Schedule\Source;

use Utopia\Database\Document;
use Utopia\Schedule\Trigger;
use Utopia\Schedule\Trigger\Cron;
use Utopia\Schedule\Trigger\Shifted;

final class Functions extends Database
{
    /**
     * @param  callable(Document): \Utopia\Database\Database  $getProjectDB
     * @param  callable(Document, string, string): bool  $isResourceBlocked
     * @param  \Closure(array<string, mixed>): int  $spread  seconds to spread a
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
        try {
            $resource = $projectDB->skipFilters(
                fn () => $projectDB->getDocument($this->collection(), $schedule['resourceId']),
                ['subQueryVariables', 'subQueryProjectVariables']
            );
        } catch (\Throwable) {
            $resource = new Document;
        }

        // The worker re-fetches the function by id when the schedule fires.
        // A failed make() is not retried until the next full snapshot, so a
        // miss must not drop the cron.
        return $resource->isEmpty()
            ? new Document(['$id' => $schedule['resourceId']])
            : $resource;
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
