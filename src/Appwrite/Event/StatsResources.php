<?php

namespace Appwrite\Event;

use Utopia\Queue\Publisher;

class StatsResources extends Event
{
    protected bool $critical = false;

    public function __construct(protected Publisher $publisher)
    {
        parent::__construct($publisher);

        $this
            ->setQueue(Event::STATS_RESOURCES_QUEUE_NAME)
            ->setClass(Event::STATS_RESOURCES_CLASS_NAME);
    }

    /**
     * Prepare the payload for the usage event.
     *
     * @return array
     */
    protected function preparePayload(): array
    {
        return [
            'project' => $this->project
        ];
    }
}
