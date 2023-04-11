<?php

namespace Appwrite\Event;

use DateTime;
use Resque;
use ResqueScheduler;
use Utopia\Database\Document;

class Func extends Event
{
    protected string $jwt = '';

    protected string $type = '';

    protected string $data = '';

    protected ?Document $function = null;

    protected ?Document $execution = null;

    public function __construct()
    {
        parent::__construct(Event::FUNCTIONS_QUEUE_NAME, Event::FUNCTIONS_CLASS_NAME);
    }

    /**
     * Sets function document for the function event.
     *
     * @param  Document  $function
     * @return self
     */
    public function setFunction(Document $function): self
    {
        $this->function = $function;

        return $this;
    }

    /**
     * Returns set function document for the function event.
     *
     * @return null|Document
     */
    public function getFunction(): ?Document
    {
        return $this->function;
    }

    /**
     * Sets execution for the function event.
     *
     * @param  Document  $execution
     * @return self
     */
    public function setExecution(Document $execution): self
    {
        $this->execution = $execution;

        return $this;
    }

    /**
     * Returns set execution for the function event.
     *
     * @return null|Document
     */
    public function getExecution(): ?Document
    {
        return $this->execution;
    }

    /**
     * Sets type for the function event.
     *
     * @param  string  $type Can be `schedule`, `event` or `http`.
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
     * Sets custom data for the function event.
     *
     * @param  string  $data
     * @return self
     */
    public function setData(string $data): self
    {
        $this->data = $data;

        return $this;
    }

    /**
     * Returns set custom data for the function event.
     *
     * @return string
     */
    public function getData(): string
    {
        return $this->data;
    }

    /**
     * Sets JWT for the function event.
     *
     * @param  string  $jwt
     * @return self
     */
    public function setJWT(string $jwt): self
    {
        $this->jwt = $jwt;

        return $this;
    }

    /**
     * Returns set JWT for the function event.
     *
     * @return string
     */
    public function getJWT(): string
    {
        return $this->jwt;
    }

    /**
     * Executes the function event and sends it to the functions worker.
     *
     * @return string|bool
     *
     * @throws \InvalidArgumentException
     */
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
            'data' => $this->data,
        ]);
    }

    /**
     * Schedules the function event and schedules it in the functions worker queue.
     *
     * @param  \DateTime|int  $at
     * @return void
     *
     * @throws \Resque_Exception
     * @throws \ResqueScheduler_InvalidTimestampException
     */
    public function schedule(DateTime|int $at): void
    {
        ResqueScheduler::enqueueAt($at, $this->queue, $this->class, [
            'project' => $this->project,
            'user' => $this->user,
            'function' => $this->function,
            'execution' => $this->execution,
            'type' => $this->type,
            'payload' => $this->payload,
            'data' => $this->data,
        ]);
    }
}
