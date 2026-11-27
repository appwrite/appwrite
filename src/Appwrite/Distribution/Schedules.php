<?php

namespace Appwrite\Distribution;

use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Query;

/**
 * A Distribution resource's row in `schedules`, which the distribution
 * scheduler reconciles the resource from every `interval` minutes. Moving
 * `resourceUpdatedAt` is what the scheduler syncs on, so every write touches it,
 * and a row is stopped rather than deleted so the scheduler sees the change.
 */
readonly class Schedules
{
    public function __construct(private Database $dbForPlatform)
    {
    }

    /**
     * Creates or refreshes the resource's schedule, active at $minutes.
     */
    public function write(Document $project, string $type, Document $resource, int $minutes): void
    {
        $schedule = $this->find($project, $type, $resource->getId());
        $changes = [
            'interval' => $minutes,
            'active' => true,
            'resourceUpdatedAt' => DateTime::now(),
        ];

        $this->dbForPlatform->getAuthorization()->skip(fn () => $schedule->isEmpty()
            ? $this->dbForPlatform->createDocument('schedules', new Document([
                'region' => (string) $project->getAttribute('region'),
                'resourceType' => $type,
                'resourceId' => $resource->getId(),
                'resourceInternalId' => (string) $resource->getSequence(),
                'projectId' => $project->getId(),
                'projectInternalId' => (string) $project->getSequence(),
                'schedule' => '',
                ...$changes,
            ]))
            : $this->dbForPlatform->updateDocument('schedules', $schedule->getId(), new Document($changes)));
    }

    public function stop(Document $project, string $type, string $resourceId): void
    {
        $schedule = $this->find($project, $type, $resourceId);
        if ($schedule->isEmpty()) {
            return;
        }

        $this->dbForPlatform->getAuthorization()->skip(fn () => $this->dbForPlatform->updateDocument('schedules', $schedule->getId(), new Document([
            'active' => false,
            'resourceUpdatedAt' => DateTime::now(),
        ])));
    }

    private function find(Document $project, string $type, string $resourceId): Document
    {
        return $this->dbForPlatform->getAuthorization()->skip(fn () => $this->dbForPlatform->findOne('schedules', [
            Query::equal('region', [(string) $project->getAttribute('region')]),
            Query::equal('resourceType', [$type]),
            Query::equal('projectId', [$project->getId()]),
            Query::equal('resourceId', [$resourceId]),
        ]));
    }
}
