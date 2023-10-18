<?php

namespace Appwrite\Event;

use Resque;
use ResqueScheduler;
use Utopia\Database\Document;

class Messaging extends Event
{
    protected ?string $messageId = null;
    private ?string $deliveryTime = null;

    public function __construct()
    {
        parent::__construct(Event::MESSAGING_QUEUE_NAME, Event::MESSAGING_CLASS_NAME);
    }

    /**
     * Sets message ID for the messaging event.
     *
     * @param string $message
     * @return self
     */
    public function setMessageId(string $messageId): self
    {
        $this->messageId = $messageId;

        return $this;
    }

    /**
     * Returns set message ID for the messaging event.
     *
     * @return string
     */
    public function getMessageId(): string
    {
        return $this->messageId;
    }

    /**
     * Sets Delivery time for the messaging event.
     *
     * @param string $deliveryTime
     * @return self
     */
    public function setDeliveryTime(string $deliveryTime): self
    {
        $this->deliveryTime = $deliveryTime;

        return $this;
    }

    /**
     * Returns set Delivery Time for the messaging event.
     *
     * @return string
     */
    public function getDeliveryTime(): string
    {
        return $this->deliveryTime;
    }

    /**
     * Set project for this event.
     *
     * @param Document $project
     * @return self
     */
    public function setProject(Document $project): self
    {
        $this->project = $project;

        return $this;
    }

    /**
     * Executes the event and sends it to the messaging worker.
     * @return string|bool
     * @throws \InvalidArgumentException
     */
    public function trigger(): string | bool
    {
        return Resque::enqueue($this->queue, $this->class, [
            'project' => $this->project,
            'user' => $this->user,
            'messageId' => $this->messageId,
        ]);
    }

    /**
     * Schedules the messaging event and schedules it in the messaging worker queue.
     *
     * @return void
     * @throws \Resque_Exception
     * @throws \ResqueScheduler_InvalidTimestampException
     */
    public function schedule(): void
    {
        ResqueScheduler::enqueueAt(new \DateTime($this->deliveryTime, new \DateTimeZone('UTC')), $this->queue, $this->class, [
            'project' => $this->project,
            'user' => $this->user,
            'messageId' => $this->messageId,
        ]);
    }
}
