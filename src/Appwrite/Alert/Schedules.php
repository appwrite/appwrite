<?php

namespace Appwrite\Alert;

use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Query;

/**
 * An alert rule's row in `schedules`, which the alerts scheduler evaluates the
 * rule from. Moving `resourceUpdatedAt` is what the scheduler syncs on, so
 * every write touches it.
 */
readonly class Schedules
{
    public function __construct(private Database $dbForPlatform)
    {
    }

    /**
     * Creates or refreshes the rule's schedule: active while the rule is enabled.
     */
    public function write(Document $rule): void
    {
        $schedule = $this->find($rule);
        $changes = [
            'active' => (bool) $rule->getAttribute('enabled', false),
            'resourceUpdatedAt' => DateTime::now(),
        ];

        $this->dbForPlatform->getAuthorization()->skip(fn () => $schedule->isEmpty()
            ? $this->dbForPlatform->createDocument('schedules', new Document([
                'region' => (string) $rule->getAttribute('region'),
                'resourceType' => SCHEDULE_RESOURCE_TYPE_ALERT,
                'resourceId' => $rule->getId(),
                'resourceInternalId' => (string) $rule->getSequence(),
                'projectId' => (string) $rule->getAttribute('projectId', ''),
                // An ID column, which takes no empty string for a rule without a project.
                'projectInternalId' => $rule->getAttribute('projectInternalId') ?: null,
                'schedule' => '',
                'data' => ['teamInternalId' => (string) $rule->getAttribute('teamInternalId')],
                ...$changes,
            ]))
            : $this->dbForPlatform->updateDocument('schedules', $schedule->getId(), new Document($changes)));
    }

    /**
     * Stops a deleted rule's schedule; the schedules sweep removes the row once
     * the rule is gone.
     */
    public function stop(Document $rule): void
    {
        $schedule = $this->find($rule);
        if ($schedule->isEmpty()) {
            return;
        }

        $this->dbForPlatform->getAuthorization()->skip(fn () => $this->dbForPlatform->updateDocument('schedules', $schedule->getId(), new Document([
            'active' => false,
            'resourceUpdatedAt' => DateTime::now(),
        ])));
    }

    private function find(Document $rule): Document
    {
        return $this->dbForPlatform->getAuthorization()->skip(fn () => $this->dbForPlatform->findOne('schedules', [
            Query::equal('region', [(string) $rule->getAttribute('region')]),
            Query::equal('resourceType', [SCHEDULE_RESOURCE_TYPE_ALERT]),
            Query::equal('projectId', [(string) $rule->getAttribute('projectId', '')]),
            Query::equal('resourceId', [$rule->getId()]),
        ]));
    }
}
