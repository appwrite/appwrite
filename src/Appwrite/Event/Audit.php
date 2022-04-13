<?php

namespace Appwrite\Event;

use Resque;

class Audit extends Event
{
    protected string $resource = '';
    protected string $mode = '';
    protected string $userAgent = '';
    protected string $ip = '';

    public function __construct()
    {
        parent::__construct(Event::AUDITS_QUEUE_NAME, Event::AUDITS_CLASS_NAME);
    }

    public function setResource(string $resource): self
    {
        $this->resource = $resource;

        return $this;
    }

    public function getResource(): string
    {
        return $this->resource;
    }

    public function setMode(string $mode): self
    {
        $this->mode = $mode;

        return $this;
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function setUserAgent(string $userAgent): self
    {
        $this->userAgent = $userAgent;

        return $this;
    }

    public function getUserAgent(): string
    {
        return $this->userAgent;
    }

    public function setIP(string $ip): self
    {
        $this->ip = $ip;

        return $this;
    }

    public function getIP(): string
    {
        return $this->ip;
    }

    public function trigger(): string|bool
    {
        return Resque::enqueue($this->queue, $this->class, [
            'project' => $this->project,
            'user' => $this->user,
            'payload' => $this->payload,
            'trigger' => $this->trigger,
            'resource' => $this->resource,
            'mode' => $this->mode,
            'ip' => $this->ip,
            'userAgent' => $this->userAgent,
            'events' => Event::generateEvents($this->getEvent(), $this->getParams())
        ]);
    }
}