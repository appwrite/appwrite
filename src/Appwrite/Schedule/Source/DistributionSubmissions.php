<?php

namespace Appwrite\Schedule\Source;

use Utopia\Database\Document;
use Utopia\Schedule\Source\Entry;
use Utopia\Schedule\Source\Row;
use Utopia\Schedule\Trigger;
use Utopia\Schedule\Trigger\Interval;
use Utopia\Schedule\Trigger\Shifted;

/**
 * An active Distribution submission, reconciled against its store by the
 * `distribution-submissions` worker every `interval` minutes from its row in
 * `schedules`. A submission keeps its slot across restarts: it is shifted by
 * `crc32(resourceId) mod interval`.
 */
final class DistributionSubmissions extends Database
{
    public function __construct(\Utopia\Database\Database $dbForPlatform)
    {
        parent::__construct($dbForPlatform, null, null);
    }

    /**
     * Nothing is looked up: the worker loads the submission and its project,
     * and stops the row when the submission is gone.
     */
    #[\Override]
    public function make(Row $row): Entry
    {
        $schedule = $row->data;
        if (!$schedule instanceof Document) {
            throw new \InvalidArgumentException('Schedule row carries no document');
        }

        $resourceId = (string) $schedule->getAttribute('resourceId');

        return new Entry(
            $this->trigger([
                'resourceId' => $resourceId,
                'interval' => (int) $schedule->getAttribute('interval', 0),
            ]),
            [
                'id' => $resourceId,
                'project' => new Document([
                    '$id' => (string) $schedule->getAttribute('projectId'),
                    '$sequence' => (string) $schedule->getAttribute('projectInternalId'),
                ]),
            ],
        );
    }

    #[\Override]
    protected function type(): string
    {
        return SCHEDULE_RESOURCE_TYPE_DISTRIBUTION_SUBMISSION;
    }

    #[\Override]
    protected function collection(): string
    {
        return 'distributionSubmissions';
    }

    #[\Override]
    protected function runnable(Document $schedule): bool
    {
        return (int) $schedule->getAttribute('interval', 0) > 0;
    }

    /**
     * The row's interval is in minutes.
     */
    #[\Override]
    protected function trigger(array $schedule): Trigger
    {
        $seconds = \max(1, (int) $schedule['interval']) * 60;

        return new Shifted(new Interval($seconds), \abs(\crc32((string) $schedule['resourceId'])) % $seconds);
    }
}
