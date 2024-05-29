<?php

namespace Appwrite\Event;

use Utopia\Queue\Client;
use Utopia\Queue\Connection;

class Growth extends Event
{
    protected string $type = '';
    protected array $resource = [];
    protected string $timestamp = '';

    public function __construct(protected Connection $connection)
    {
        parent::__construct($connection);

        $this->timestamp = (new \DateTime())->format(\DateTime::ATOM);

        $this
            ->setQueue(Event::GROWTH_QUEUE_NAME)
            ->setClass(Event::GROWTH_CLASS_NAME);
    }

    /**
     * Sets the type of growth event.
     *
     * @param string $type
     *
     * @return self
     */
    public function setType(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    /**
     * Returns the set growth event type.
     *
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Sets the resource of the growth event.
     * 
     * @param array $resource
     * 
     * @return self
     */
    public function setResource(array $resource): self
    {
        $this->resource = $resource;

        return $this;
    }

    /**
     * Returns the set resource of the growth event.
     * 
     * @return array
     */
    public function getResource(): array
    {
        return $this->resource;
    }

    /**
     * Executes the growth event and sends it to the growth worker.
     *
     * @return string|bool
     * @throws \InvalidArgumentException
     */
    public function trigger(): string|bool
    {
        $client = new Client($this->queue, $this->connection);

        return $client->enqueue([
            'project' => $this->project,
            'user' => $this->user,
            'event' => $this->event,
            'resource' => $this->resource,
            'timestamp' => $this->timestamp
        ]);
    }
}
