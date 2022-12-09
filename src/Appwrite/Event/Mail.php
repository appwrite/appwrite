<?php

namespace Appwrite\Event;

use Resque;
use Utopia\Database\Document;

class Mail extends Event
{
    protected array $params = [];
    protected string $recipient = '';
    protected string $locale = '';

    public function __construct()
    {
        parent::__construct(Event::MAILS_QUEUE_NAME, Event::MAILS_CLASS_NAME);
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
     * Sets locale for the mail event.
     *
     * @param string $locale
     * @return self
     */
    public function setLocale(string $locale): self
    {
        $this->locale = $locale;

        return $this;
    }

    /**
     * Returns set locale for the mail event.
     *
     * @return string
     */
    public function getLocale(): string
    {
        return $this->locale;
    }

    /**
     * Executes the event and sends it to the mails worker.
     *
     * @return string|bool
     * @throws \InvalidArgumentException
     */
    public function trigger(): string|bool
    {
        return Resque::enqueue($this->queue, $this->class, $this->params);
        // [
        //     'project' => $this->project,
        //     'user' => $this->user,
        //     'payload' => $this->payload,
        //     'recipient' => $this->recipient,
        //     'url' => $this->params['url'] ?? '',
        //     'locale' => $this->locale,
        //     'type' => $this->type,
        //     'name' => $this->params['name'] ?? '',
        //     'team' => $this->params['team'] ?? '',
        //     'events' => Event::generateEvents($this->getEvent(), $this->getParams())
        // ]);
    }
}
