<?php

namespace Appwrite\Maintenance;

use Appwrite\Event\Message\Maintenance as MaintenanceMessage;
use Appwrite\Event\Publisher\Maintenance as MaintenancePublisher;
use Utopia\Schedule\Occurrence;

/**
 * Scheduler handler that hands each due moment to the maintenance queue, so a tick never waits on the database or
 * the deletes queue.
 */
final class Dispatcher
{
    /** The latest due already published: a tick redelivered after a refused publish resends only what came after it. */
    private int $watermark = 0;

    public function __construct(
        private readonly MaintenancePublisher $publisher,
        private readonly Cycle $cycle,
        private readonly string $region,
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
            if ($due <= $this->watermark) {
                continue;
            }

            $published = $this->publisher->enqueue(new MaintenanceMessage(
                slot: $this->cycle->slot($due),
                slots: $this->cycle->slots,
                due: $due,
                period: $this->cycle->seconds(),
                region: $this->region,
            ));

            if ($published === false) {
                throw new \RuntimeException('Failed to enqueue maintenance for slot due at ' . $due);
            }

            $this->watermark = $due;
        }
    }
}
