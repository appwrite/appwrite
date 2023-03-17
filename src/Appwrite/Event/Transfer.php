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

    public function __construct()
    {
        parent::__construct(Event::TRANSFER_QUEUE_NAME, Event::TRANSFER_CLASS_NAME);
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
     * Sets transfer type for the transfer event.
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
     * Returns set transfer type for the transfer event.
     * 
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Executes the transfer event and sends it to the transfers worker.
     *
     * @return string|bool
     * @throws \InvalidArgumentException
     */
    public function trigger(): string|bool
    {
        return Resque::enqueue($this->queue, $this->class, [
            'project' => $this->project,
            'user' => $this->user,
            'transfer' => $this->transfer
        ]);
    }

    /**
     * Schedules the transfer event and schedules it in the transfers worker queue.
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
            'transfer' => $this->transfer
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function setUser(Document $user): self
    {
        parent::setUser($user);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function setProject(Document $project): self
    {
        parent::setProject($project);

        return $this;
    }
}
