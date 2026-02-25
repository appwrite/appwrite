<?php

namespace Appwrite\Event;

use Utopia\Queue\Publisher;
use Utopia\System\System;

class StatsResources extends Event
{
    protected bool $critical = false;

    public function __construct(protected Publisher $publisher)
    {
        parent::__construct($publisher);

        $this
            ->setQueue(System::getEnv('_APP_STATS_RESOURCES_QUEUE_NAME', Event::STATS_RESOURCES_QUEUE_NAME))
            ->setClass(System::getEnv('_APP_STATS_RESOURCES_CLASS_NAME', Event::STATS_RESOURCES_CLASS_NAME));
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
