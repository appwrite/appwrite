<?php

namespace Appwrite\Event;

use DateTime;
use Resque;
use ResqueScheduler;
use Utopia\Database\Document;

class Transfer extends Event
{
    protected string $type = '';
    protected ?Document $transfer = null;
    protected ?Document $source = null;
    protected ?Document $destination = null;

    public function __construct()
    {
        parent::__construct(Event::TRANSFER_QUEUE_NAME, Event::TRANSFER_CLASS_NAME);
    }

    /**
     * Sets type for the transfer event.
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
     * Returns set type for the function event.
     * 
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Sets transfer document for the transfer event.
     *
     * @param Document $transfer
     * @return self
     */
    public function setTransfer(Document $transfer): self
    {
        $this->transfer = $transfer;

        return $this;
    }

    /**
     * Returns set transfer document for the function event.
     *
     * @return null|Document
     */
    public function getTransfer(): ?Document
    {
        return $this->transfer;
    }

    /**
     * Sets source document for the transfer event.
     *
     * @param Document $source
     * @return self
     */
    public function setSource(Document $source): self
    {
        $this->source = $source;

        return $this;
    }

    /**
     * Returns set source document for the function event.
     *
     * @return null|Document
     */
    public function getSource(): ?Document
    {
        return $this->source;
    }

    /**
     * Sets destination document for the transfer event.
     *
     * @param Document $destination
     * @return self
     */
    public function setDestination(Document $destination): self
    {
        $this->destination = $destination;

        return $this;
    }

    /**
     * Returns set destination document for the function event.
     *
     * @return null|Document
     */
    public function getDestination(): ?Document
    {
        return $this->destination;
    }

    /**
     * Executes the function event and sends it to the functions worker.
     *
     * @return string|bool
     * @throws \InvalidArgumentException
     */
    public function trigger(): string|bool
    {
        return Resque::enqueue($this->queue, $this->class, [
            'project' => $this->project,
            'user' => $this->user,
            'transfer' => $this->transfer,
            'source' => $this->source,
            'destination' => $this->destination,
            'type' => $this->type,
            'payload' => $this->payload
        ]);
    }

    /**
     * Schedules the function event and schedules it in the functions worker queue.
     *
     * @param \DateTime|int $at
     * @return void
     * @throws \Resque_Exception
     * @throws \ResqueScheduler_InvalidTimestampException
     */
    public function schedule(DateTime|int $at): void
    {
        ResqueScheduler::enqueueAt($at, $this->queue, $this->class, [
            'project' => $this->project,
            'user' => $this->user,
            'transfer' => $this->transfer,
            'source' => $this->source,
            'destination' => $this->destination,
            'type' => $this->type,
            'payload' => $this->payload
        ]);
    }
}
