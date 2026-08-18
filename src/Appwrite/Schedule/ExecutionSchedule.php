<?php

namespace Appwrite\Schedule;

use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Schedule\Trigger;
use Utopia\Schedule\Trigger\At;

final class ExecutionSchedule extends DatabaseSchedule
{
    #[\Override]
    protected function type(): string
    {
        return SCHEDULE_RESOURCE_TYPE_EXECUTION;
    }

    #[\Override]
    protected function collection(): string
    {
        return RESOURCE_TYPE_EXECUTIONS;
    }

    #[\Override]
    protected function trigger(array $schedule): Trigger
    {
        return new At(new \DateTimeImmutable((string) $schedule['schedule']));
    }

    #[\Override]
    protected function resource(Database $projectDB, array $schedule): Document
    {
        try {
            $resource = $projectDB->getDocument($this->collection(), $schedule['resourceId']);
        } catch (\Throwable) {
            $resource = new Document();
        }

        return $resource->isEmpty()
            ? new Document(['$id' => $schedule['resourceId']])
            : $resource;
    }
}
