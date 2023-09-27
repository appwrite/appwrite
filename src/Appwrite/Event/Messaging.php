<?php

namespace Appwrite\Event;

use Resque;
use Utopia\Database\Document;

class Messaging extends Event
{
    protected ?string $messageId = null;

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
     * Executes the event and sends it to the messaging worker.
     *
     * @return string|bool
     * @throws \InvalidArgumentException
     */
    public function trigger(): string|bool
    {
        return Resque::enqueue($this->queue, $this->class, [
            'project' => $this->project,
            'user' => $this->user,
            'messageId' => $this->messageId,
        ]);
    }
}
