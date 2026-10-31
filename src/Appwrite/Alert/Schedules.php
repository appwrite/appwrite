<?php

namespace Appwrite\Alert;

use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Query;

/**
 * A monitor's row in `schedules`, which the alerts scheduler evaluates the
 * monitor from. Moving `resourceUpdatedAt` is what the scheduler syncs on, so
 * every write touches it.
 */
readonly class Schedules
{
    public function __construct(private Database $dbForPlatform)
    {
    }

    /**
     * Creates or refreshes the monitor's schedule: active while the monitor is enabled.
     */
    public function write(Document $monitor): void
    {
        $schedule = $this->find($monitor);
        $changes = [
            'active' => (bool) $monitor->getAttribute('enabled', false),
            'resourceUpdatedAt' => DateTime::now(),
        ];

        $this->dbForPlatform->getAuthorization()->skip(fn () => $schedule->isEmpty()
            ? $this->dbForPlatform->createDocument('schedules', new Document([
                'region' => (string) $monitor->getAttribute('region'),
                'resourceType' => SCHEDULE_RESOURCE_TYPE_MONITOR,
                'resourceId' => $monitor->getId(),
                'resourceInternalId' => (string) $monitor->getSequence(),
                'projectId' => (string) $monitor->getAttribute('projectId', ''),
                // An ID column, which takes no empty string for a monitor without a project.
                'projectInternalId' => $monitor->getAttribute('projectInternalId') ?: null,
                'schedule' => '',
                ...$changes,
            ]))
            : $this->dbForPlatform->updateDocument('schedules', $schedule->getId(), new Document($changes)));
    }

    /**
     * Stops a deleted monitor's schedule; the schedules sweep removes the row once
     * the monitor is gone.
     */
    public function stop(Document $monitor): void
    {
        $schedule = $this->find($monitor);
        if ($schedule->isEmpty()) {
            return;
        }

        $this->dbForPlatform->getAuthorization()->skip(fn () => $this->dbForPlatform->updateDocument('schedules', $schedule->getId(), new Document([
            'active' => false,
            'resourceUpdatedAt' => DateTime::now(),
        ])));
    }

    private function find(Document $monitor): Document
    {
        return $this->dbForPlatform->getAuthorization()->skip(fn () => $this->dbForPlatform->findOne('schedules', [
            Query::equal('region', [(string) $monitor->getAttribute('region')]),
            Query::equal('resourceType', [SCHEDULE_RESOURCE_TYPE_MONITOR]),
            Query::equal('projectId', [(string) $monitor->getAttribute('projectId', '')]),
            Query::equal('resourceId', [$monitor->getId()]),
        ]));
    }
}
