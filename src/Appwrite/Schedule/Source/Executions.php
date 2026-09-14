<?php

namespace Appwrite\Schedule\Source;

use Utopia\Database\Document;
use Utopia\Schedule\Trigger;
use Utopia\Schedule\Trigger\At;

final class Executions extends Database
{
    #[\Override]
    protected function type(): string
    {
        return SCHEDULE_RESOURCE_TYPE_EXECUTION;
    }

    #[\Override]
    protected function collection(): string
    {
        return RESOURCE_TYPE_FUNCTIONS;
    }

    #[\Override]
    protected function trigger(array $schedule): Trigger
    {
        return new At(new \DateTimeImmutable((string) $schedule['schedule']));
    }

    #[\Override]
    protected function blockResourceId(array $schedule): string
    {
        return (string) ($schedule['data']['functionId'] ?? '');
    }

    #[\Override]
    protected function resource(\Utopia\Database\Database $projectDB, array $schedule): Document
    {
        return new Document([
            '$id' => $schedule['resourceId'],
            'resourceId' => $schedule['data']['functionId'] ?? '',
        ]);
    }
}
