<?php

namespace Appwrite\Schedule\Source;

use Utopia\Database\Document;
use Utopia\Schedule\Source\Entry;
use Utopia\Schedule\Source\Row;
use Utopia\Schedule\Trigger;
use Utopia\Schedule\Trigger\At;

/**
 * One timer per escalating alert, read from the alert's row in `schedules`:
 * it fires once, when the alert's next escalation step is due. The alerts
 * worker notifies the step and moves the row on to the step after.
 */
final class Escalations extends Database
{
    public function __construct(\Utopia\Database\Database $dbForPlatform)
    {
        // The worker loads the alert and its project, so make() reads neither.
        parent::__construct($dbForPlatform, null, null);
    }

    #[\Override]
    public function make(Row $row): Entry
    {
        $schedule = $row->data;
        if (!$schedule instanceof Document) {
            throw new \InvalidArgumentException('Schedule row carries no document');
        }

        $projectId = (string) $schedule->getAttribute('projectId');

        return new Entry(
            $this->trigger(['schedule' => (string) $schedule->getAttribute('schedule')]),
            [
                'monitorId' => '',
                'alertId' => (string) $schedule->getAttribute('resourceId'),
                'project' => $projectId === '' ? new Document() : new Document([
                    '$id' => $projectId,
                    '$sequence' => (string) $schedule->getAttribute('projectInternalId'),
                ]),
            ],
        );
    }

    #[\Override]
    protected function runnable(Document $schedule): bool
    {
        return (string) $schedule->getAttribute('schedule', '') !== '';
    }

    #[\Override]
    protected function type(): string
    {
        return SCHEDULE_RESOURCE_TYPE_ESCALATION;
    }

    #[\Override]
    protected function collection(): string
    {
        return 'monitorAlerts';
    }

    #[\Override]
    protected function trigger(array $schedule): Trigger
    {
        return new At(new \DateTimeImmutable((string) $schedule['schedule']), catchUp: true);
    }
}
