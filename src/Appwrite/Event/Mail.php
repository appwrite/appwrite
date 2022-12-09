<?php

namespace Appwrite\Event;

use Resque;
use Utopia\Database\Document;

class Mail extends Event
{
    protected array $params = [];
    protected string $type = '';
    protected string $recipient = '';
    protected string $locale = '';

    public function __construct()
    {
        parent::__construct(Event::MAILS_QUEUE_NAME, Event::MAILS_CLASS_NAME);
    }

    /**
     * Sets team for the mail event.
     *
     * @param Document $team
     * @return self
     */
    public function setTeam(Document $team): self
    {
        $this->params['team'] = $team;

        return $this;
    }

    /**
     * Returns set team for the mail event.
     *
     * @return null|Document
     */
    public function getTeam(): ?Document
    {
        return $this->params['team'] ?? null;
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
        $this->params['url'] = $url;

        return $this;
    }

    /**
     * Returns set url for the mail event.
     *
     * @return string
     */
    public function getURL(): string
    {
        return $this->params['url'] ?? '';
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
        $this->params['name'] = $name;

        return $this;
    }

    /**
     * Returns set name for the mail event.
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->params['name'] ?? '';
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
        return Resque::enqueue($this->queue, $this->class, [
            'project' => $this->project,
            'user' => $this->user,
            'payload' => $this->payload,
            'recipient' => $this->recipient,
            'url' => $this->params['url'] ?? '',
            'locale' => $this->locale,
            'type' => $this->type,
            'name' => $this->params['name'] ?? '',
            'team' => $this->params['team'] ?? '',
            'events' => Event::generateEvents($this->getEvent(), $this->getParams())
        ]);
    }
}
