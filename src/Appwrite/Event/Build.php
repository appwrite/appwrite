<?php

namespace Appwrite\Event;

use Utopia\Database\Document;
use Utopia\Queue\Client;
use Utopia\Queue\Connection;

class Build extends Event
{
    protected string $type = '';
    protected ?Document $resource = null;
    protected ?Document $deployment = null;

    public function __construct(protected Connection $connection)
    {
        parent::__construct(Event::BUILDS_QUEUE_NAME, Event::BUILDS_CLASS_NAME, $connection);
    }

    /**
     * Sets resource document for the build event.
     *
     * @param Document $resource
     * @return self
     */
    public function setResource(Document $resource): self
    {
        $this->resource = $resource;

        return $this;
    }

    /**
     * Returns set resource document for the build event.
     *
     * @return null|Document
     */
    public function getResource(): ?Document
    {
        return $this->resource;
    }

    /**
     * Sets deployment for the build event.
     *
     * @param Document $deployment
     * @return self
     */
    public function setDeployment(Document $deployment): self
    {
        $this->deployment = $deployment;

        return $this;
    }

    /**
     * Returns set deployment for the build event.
     *
     * @return null|Document
     */
    public function getDeployment(): ?Document
    {
        return $this->deployment;
    }

    /**
     * Sets type for the build event.
     *
     * @param string $type Can be `BUILD_TYPE_DEPLOYMENT` or `BUILD_TYPE_RETRY`.
     * @return self
     */
    public function setType(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    /**
     * Returns set type for the function event.
     *
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Executes the function event and sends it to the functions worker.
     *
     * @return string|bool
     * @throws \InvalidArgumentException
     */
    public function trigger(): string|bool
    {
        $client = new Client($this->queue, $this->connection);

        $events = $this->getEvent() ? Event::generateEvents($this->getEvent(), $this->getParams()) : null;

        return $client->enqueue([
            'project' => $this->project,
            'resource' => $this->resource,
            'deployment' => $this->deployment,
            'type' => $this->type
        ]);
    }
}
