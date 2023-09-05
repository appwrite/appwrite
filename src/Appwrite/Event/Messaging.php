<?php

namespace Appwrite\Event;

use Resque;
use Utopia\Database\Document;

class Messaging extends Event
{
    protected ?Document $message = null;

    public function __construct()
    {
        parent::__construct(Event::MESSAGING_QUEUE_NAME, Event::MESSAGING_CLASS_NAME);
    }
    


    /**
     * Sets message record for the messaging event.
     *
     * @param Document $message
     * @return self
     */
    public function setMessage(Document $message): self
    {
        $this->message = $message;

        return $this;
    }

    /**
     * Returns set message for the messaging event.
     *
     * @return Document
     */
    public function getMessage(): Document
    {
        return $this->message;
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
            'message' => $this->message,
        ]);
    }
}
