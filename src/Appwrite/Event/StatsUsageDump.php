<?php

namespace Appwrite\Event;

use Utopia\Queue\Publisher;

class StatsUsageDump extends Event
{
    protected array $stats;

    public function __construct(protected Publisher $publisher)
    {
        parent::__construct($publisher);

        $this
            ->setQueue(Event::STATS_USAGE_DUMP_QUEUE_NAME)
            ->setClass(Event::STATS_USAGE_DUMP_CLASS_NAME);
    }

    /**
     * Add Stats.
     *
     * @param array $stats
     * @return self
     */
    public function setStats(array $stats): self
    {
        $this->stats = $stats;

        return $this;
    }

    /**
     * Prepare the payload for the usage dump event.
     *
     * @return array
     */
    protected function preparePayload(): array
    {
        return [
            'stats' => $this->stats,
        ];
    }
}
