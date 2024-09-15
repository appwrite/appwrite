<?php

namespace Appwrite\Event;

use Utopia\Queue\Client;
use Utopia\Queue\Connection;

class UsageInfinity extends Event
{

    public function __construct(protected Connection $connection)
    {
        parent::__construct($connection);

        $this
            ->setQueue(Event::USAGE_INFINITY_QUEUE_NAME)
            ->setClass(Event::USAGE_INFINITY_CLASS_NAME);
    }


    /**
     * Sends project to the usage infinity worker.
     *
     * @return string|bool
     */
    public function trigger(): string|bool
    {
        var_dump($this->getProject());
        $client = new Client($this->queue, $this->connection);
        return $client->enqueue([
            'project' => $this->getProject(),
        ]);
    }
}
