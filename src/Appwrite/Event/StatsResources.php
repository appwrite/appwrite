<?php

namespace Appwrite\Event;

use Utopia\Queue\Connection;

class StatsResources extends Event
{
    public function __construct(protected Connection $connection)
    {
        parent::__construct($connection);

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

    /**
     * Sends metrics to the usage worker.
     *
     * @return string|bool
     */
    public function trigger(): string|bool
    {
        parent::trigger();
        return true;
    }
}
