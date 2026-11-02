<?php

namespace Appwrite\Alert;

use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Query;

/**
 * Removes monitors: the one a request deletes, and those scoped to a database,
 * bucket, function or site that is itself being deleted. A removed monitor's
 * schedule stops, its open alert is resolved, and its alert history stays.
 */
readonly class Monitors
{
    private const int BATCH = 100;

    public function __construct(private Database $dbForPlatform)
    {
    }

    public function delete(Document $monitor): void
    {
        // Stopped first: if the delete then fails the monitor is still there to retry,
        // while a schedule left active after the monitor is gone would fire forever.
        (new Schedules($this->dbForPlatform))->stop($monitor);

        $this->dbForPlatform->getAuthorization()->skip(function () use ($monitor): void {
            // By monitor rather than by `alertId`, which an evaluation that failed to announce its alert never stored.
            $alert = $this->dbForPlatform->findOne('monitorAlerts', [
                Query::equal('monitorInternalId', [(string) $monitor->getSequence()]),
                Query::equal('status', [State::Firing->value]),
            ]);
            if (!$alert->isEmpty()) {
                $this->dbForPlatform->updateDocument('monitorAlerts', $alert->getId(), new Document([
                    'status' => State::Resolved->value,
                    'resolvedAt' => DateTime::now(),
                ]));
            }

            $this->dbForPlatform->deleteDocument('monitors', $monitor->getId());
        });
    }

    /**
     * Deletes every monitor of the project scoped to the resource.
     *
     * @param string $resourceType the monitors' `resourceType`, such as `bucket`
     */
    public function deleteWatching(Document $project, string $resourceType, Document $resource): void
    {
        // Each pass deletes what it found, so the next reads the rest from the start.
        do {
            $monitors = $this->dbForPlatform->getAuthorization()->skip(fn () => $this->dbForPlatform->find('monitors', [
                Query::equal('projectInternalId', [(string) $project->getSequence()]),
                Query::equal('resourceType', [$resourceType]),
                Query::equal('resourceInternalId', [(string) $resource->getSequence()]),
                Query::limit(self::BATCH),
            ]));

            foreach ($monitors as $monitor) {
                $this->delete($monitor);
            }
        } while (\count($monitors) === self::BATCH);
    }
}
