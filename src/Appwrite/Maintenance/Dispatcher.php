<?php

namespace Appwrite\Maintenance;

use Appwrite\Event\Message\Maintenance as MaintenanceMessage;
use Appwrite\Event\Publisher\Maintenance as MaintenancePublisher;
use Utopia\Schedule\Occurrence;

/**
 * Scheduler handler that hands each due moment to the maintenance queue, so a tick never waits on the database or
 * the deletes queue.
 */
final readonly class Dispatcher
{
    public function __construct(
        private MaintenancePublisher $publisherForMaintenance,
        private Cycle $cycle,
    ) {
    }

    /**
     * @param list<Occurrence> $occurrences
     *
     * @throws \RuntimeException when a moment is not published, so the tick is not committed and is delivered again
     */
    public function __invoke(array $occurrences): void
    {
        foreach ($occurrences as $occurrence) {
            $due = $occurrence->due->getTimestamp();

            $published = $this->publisherForMaintenance->enqueue(new MaintenanceMessage(
                slot: $this->cycle->slot($due),
                slots: $this->cycle->slots,
                due: $due,
                period: $this->cycle->seconds(),
            ));

            if ($published === false) {
                throw new \RuntimeException('Failed to enqueue maintenance for slot due at ' . $due);
            }
        }
    }
}
