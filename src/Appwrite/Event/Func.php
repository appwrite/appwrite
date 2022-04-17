<?php

namespace Appwrite\Event;

use DateTime;
use Resque;
use ResqueScheduler;
use Utopia\Database\Document;

class Func extends Event
{
    protected ?Document $function = null;
    protected ?Document $execution = null;
    protected string $jwt = '';
    protected string $type = '';
    protected string $data = '';

    public function __construct()
    {
        parent::__construct(Event::FUNCTIONS_QUEUE_NAME, Event::FUNCTIONS_CLASS_NAME);
    }

    public function setFunction(Document $function): self
    {
        $this->function = $function;

        return $this;
    }

    public function getFunction(): ?Document
    {
        return $this->function;
    }

    public function setExecution(Document $execution): self
    {
        $this->execution = $execution;

        return $this;
    }

    public function getExecution(): ?Document
    {
        return $this->execution;
    }

    public function setType(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setData(string $data): self
    {
        $this->data = $data;

        return $this;
    }

    public function getData(): string
    {
        return $this->data;
    }

    public function setJWT(string $jwt): self
    {
        $this->jwt = $jwt;

        return $this;
    }

    public function getJWT(): string
    {
        return $this->jwt;
    }

    public function trigger(): string|bool
    {
        return Resque::enqueue($this->queue, $this->class, [
            'project' => $this->project,
            'user' => $this->user,
            'function' => $this->function,
            'execution' => $this->execution,
            'type' => $this->type,
            'jwt' => $this->jwt,
            'payload' => $this->payload,
            'data' => $this->data
        ]);
    }

    public function schedule(DateTime|int $at): void
    {
        ResqueScheduler::enqueueAt($at, $this->queue, $this->class, [
            'project' => $this->project,
            'user' => $this->user,
            'function' => $this->function,
            'execution' => $this->execution,
            'type' => $this->type,
            'payload' => $this->payload,
            'data' => $this->data
        ]);
    }
}