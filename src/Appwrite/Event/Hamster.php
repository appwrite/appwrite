<?php

namespace Appwrite\Event;

use Utopia\Database\Document;
use Utopia\Queue\Client;
use Utopia\Queue\Connection;

class Hamster extends Event
{
    protected string $type = '';
    protected ?Document $project = null;
    protected ?Document $organization = null;
    protected ?Document $user = null;

    public function __construct(protected Connection $connection)
    {
        parent::__construct($connection);

        $this
            ->setQueue(Event::HAMSTER_QUEUE_NAME)
            ->setClass(Event::HAMSTER_CLASS_NAME);
    }

    /**
     * Sets the type for the hamster event.
     *
     * @param string $type
     * @return self
     */
    public function setType(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    /**
     * Returns the set type for the hamster event.
     *
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Sets the project for the hamster event.
     * 
     * @param Document $project
     */
    public function setProject(Document $project): self
    {
        $this->project = $project;

        return $this;
    }

    /**
     * Returns the set project for the hamster event.
     * 
     * @return Document
     */
    public function getProject(): Document
    {
        return $this->project;
    }

    /**
     * Sets the organization for the hamster event.
     * 
     * @param Document $organization
     */
    public function setOrganization(Document $organization): self
    {
        $this->organization = $organization;

        return $this;
    }

    /**
     * Returns the set organization for the hamster event.
     * 
     * @return string
     */
    public function getOrganization(): Document
    {
        return $this->organization;
    }

    /**
     * Sets the user for the hamster event.
     * 
     * @param Document $user
     */
    public function setUser(Document $user): self
    {
        $this->user = $user;

        return $this;
    }

    /**
     * Returns the set user for the hamster event.
     * 
     * @return Document
     */
    public function getUser(): Document
    {
        return $this->user;
    }

    /**
     * Executes the function event and sends it to the functions worker.
     *
     * @return string|bool
     * @throws \InvalidArgumentException
     */
    public function trigger(): string|bool
    {
        if ($this->paused) {
            return false;
        }

        $client = new Client($this->queue, $this->connection);

        $events = $this->getEvent() ? Event::generateEvents($this->getEvent(), $this->getParams()) : null;

        return $client->enqueue([
            'type' => $this->type,
            'project' => $this->project,
            'organization' => $this->organization,
            'user' => $this->user,
            'events' => $events,
        ]);
    }

    /**
     * Generate a function event from a base event
     *
     * @param Event $event
     *
     * @return self
     *
     */
    public function from(Event $event): self
    {
        $this->event = $event->getEvent();
        $this->params = $event->getParams();
        return $this;
    }
}
