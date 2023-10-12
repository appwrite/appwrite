<?php

namespace Appwrite\Event;

use ResqueScheduler;
use Utopia\Database\DateTime;

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
     * Executes the event and sends it to the messaging worker.
     */
    public function trigger(): string | bool
    {
        ResqueScheduler::enqueueAt(!empty($this->deliveryTime) ? $this->deliveryTime : DateTime::now(), $this->queue, $this->class, [
            'project' => $this->project,
            'user' => $this->user,
            'messageId' => $this->messageId,
        ]);
        return true;
    }
}
