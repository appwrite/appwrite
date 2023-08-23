<?php

namespace Appwrite\Event;

use Resque;

class Phone extends Event
{
    protected string $recipient = '';

    protected string $message = '';

    public function __construct()
    {
        parent::__construct(Event::MESSAGING_QUEUE_NAME, Event::MESSAGING_CLASS_NAME);
    }

    /**
     * Sets recipient for the messaging event.
     *
     * @param  string  $recipient
     * @return self
     */
    public function setRecipient(string $recipient): self
    {
        $this->recipient = $recipient;

        return $this;
    }

    /**
     * Returns set recipient for this messaging event.
     *
     * @return string
     */
    public function getRecipient(): string
    {
        return $this->recipient;
    }

    /**
     * Sets url for the messaging event.
     *
     * @param  string  $message
     * @return self
     */
    public function setMessage(string $message): self
    {
        $this->message = $message;

        return $this;
    }

    /**
     * Returns set url for the messaging event.
     *
     * @return string
     */
    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * Executes the event and sends it to the messaging worker.
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
            'payload' => $this->payload,
            'recipient' => $this->recipient,
            'message' => $this->message,
            'events' => Event::generateEvents($this->getEvent(), $this->getParams()),
        ]);
    }
}
