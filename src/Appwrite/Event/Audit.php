<?php

namespace Appwrite\Event;

use Utopia\Queue\Client;
use Utopia\Queue\Connection;

class Audit extends Event
{
    protected string $resource = '';
    protected string $mode = '';
    protected string $userAgent = '';
    protected string $ip = '';

    public function __construct(protected Connection $connection)
    {
        parent::__construct($connection);

        $this
            ->setQueue(Event::AUDITS_QUEUE_NAME)
            ->setClass(Event::BUILDS_CLASS_NAME);

    }

    /**
     * Set resource for this audit event.
     *
     * @param string $resource
     * @return self
     */
    public function setResource(string $resource): self
    {
        $this->resource = $resource;

        return $this;
    }

    /**
     * Returns the set audit resource.
     *
     * @return string
     */
    public function getResource(): string
    {
        return $this->resource;
    }

    /**
     * Set mode for this audit event
     *
     * @param string $mode
     * @return self
     */
    public function setMode(string $mode): self
    {
        $this->mode = $mode;

        return $this;
    }

    /**
     * Returns the set audit mode.
     *
     * @return string
     */
    public function getMode(): string
    {
        return $this->mode;
    }

    /**
     * Set user agent for this audit event.
     *
     * @param string $userAgent
     * @return self
     */
    public function setUserAgent(string $userAgent): self
    {
        $this->userAgent = $userAgent;

        return $this;
    }

    /**
     * Returns the set audit user agent.
     *
     * @return string
     */
    public function getUserAgent(): string
    {
        return $this->userAgent;
    }

    /**
     * Set IP for this audit event.
     *
     * @param string $ip
     * @return self
     */
    public function setIP(string $ip): self
    {
        $this->ip = $ip;

        return $this;
    }

    /**
     * Returns the set audit IP.
     *
     * @return string
     */
    public function getIP(): string
    {
        return $this->ip;
    }

    /**
     * Executes the event and sends it to the audit worker.
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
            'payload' => $this->payload,
            'resource' => $this->resource,
            'mode' => $this->mode,
            'ip' => $this->ip,
            'userAgent' => $this->userAgent,
            'event' => $this->event,
        ]);
    }
}
