<?php

namespace Appwrite\Event;

use Utopia\Database\Document;
use Utopia\Queue\Client;
use Utopia\Queue\Connection;

class Mail extends Event
{
    protected string $recipient = '';
    protected string $url = '';
    protected string $type = '';
    protected string $name = '';
    protected string $locale = '';
    protected ?Document $team = null;

    public function __construct(protected Connection $connection)
    {
        parent::__construct($connection);

        $this
            ->setQueue(Event::MAILS_QUEUE_NAME)
            ->setClass(Event::MAILS_CLASS_NAME);
    }

    /**
     * Sets team for the mail event.
     *
     * @param Document $team
     * @return self
     */
    public function setTeam(Document $team): self
    {
        $this->team = $team;

        return $this;
    }

    /**
     * Returns set team for the mail event.
     *
     * @return null|Document
     */
    public function getTeam(): ?Document
    {
        return $this->team;
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
     * Sets url for the mail event.
     *
     * @param string $url
     * @return self
     */
    public function setUrl(string $url): self
    {
        $this->url = $url;

        return $this;
    }

    /**
     * Returns set url for the mail event.
     *
     * @return string
     */
    public function getURL(): string
    {
        return $this->url;
    }

    /**
     * Sets type for the mail event (use the constants starting with MAIL_TYPE_*).
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
     * Returns set type for the mail event.
     *
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
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
        $client = new Client($this->queue, $this->connection);

        $events = $this->getEvent() ? Event::generateEvents($this->getEvent(), $this->getParams()) : null;

        return $client->enqueue([
            'project' => $this->project,
            'user' => $this->user,
            'payload' => $this->payload,
            'recipient' => $this->recipient,
            'url' => $this->url,
            'locale' => $this->locale,
            'type' => $this->type,
            'name' => $this->name,
            'team' => $this->team,
            'events' => Event::generateEvents($this->getEvent(), $this->getParams())
        ]);
    }
}
