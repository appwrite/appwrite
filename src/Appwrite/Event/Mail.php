<?php

namespace Appwrite\Event;

use Resque;
use Utopia\Database\Document;

class Mail extends Event
{
    protected string $recipient = '';
    protected string $from = '';
    protected string $name = '';
    protected string $subject = '';
    protected string $body = '';
    protected array $smtp = [];

    public function __construct()
    {
        parent::__construct(Event::MAILS_QUEUE_NAME, Event::MAILS_CLASS_NAME);
    }

    /**
     * Sets subject for the mail event.
     *
     * @param string $subject
     * @return self
     */
    public function setSubject(string $subject): self
    {
        $this->subject = $subject;

        return $this;
    }

    /**
     * Returns set team for the mail event.
     *
     * @return string
     */
    public function getSubject(): string
    {
        return $this->subject;
    }

    /**
     * Sets recipient for the mail event.
     *
     * @param string $recipient
     * @return self
     */
    public function setRecipient(string $recipient): self
    {
        $this->recipient = $recipient;

        return $this;
    }

    /**
     * Returns set recipient for mail event.
     *
     * @return string
     */
    public function getRecipient(): string
    {
        return $this->recipient;
    }

    /**
     * Sets from for the mail event.
     *
     * @param string $from
     * @return self
     */
    public function setFrom(string $from): self
    {
        $this->from = $from;

        return $this;
    }

    /**
     * Returns from for mail event.
     *
     * @return string
     */
    public function getFrom(): string
    {
        return $this->from;
    }

    /**
     * Sets body for the mail event.
     *
     * @param string $body
     * @return self
     */
    public function setBody(string $body): self
    {
        $this->body = $body;

        return $this;
    }

    /**
     * Returns body for the mail event.
     *
     * @return string
     */
    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * Sets name for the mail event.
     *
     * @param string $name
     * @return self
     */
    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Returns set name for the mail event.
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Set SMTP
     *
     * @param array $smtp
     * @return self
     */
    public function setSmtp(array $smtp): self
    {
        $this->smtp = $smtp;
        return $this;
    }

    /**
     * Get SMTP
     *
     * @return string
     */
    public function getSmtp(): array
    {
        return $this->smtp;
    }

    /**
     * Executes the event and sends it to the mails worker.
     *
     * @return string|bool
     * @throws \InvalidArgumentException
     */
    public function trigger(): string|bool
    {
        return Resque::enqueue($this->queue, $this->class, [
            'from' => $this->from,
            'recipient' => $this->recipient,
            'name' => $this->name,
            'subject' => $this->subject,
            'body' => $this->body,
            'events' => Event::generateEvents($this->getEvent(), $this->getParams())
        ]);
    }
}
