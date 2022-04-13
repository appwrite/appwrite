<?php

namespace Appwrite\Event;

use Resque;
use Utopia\Database\Document;

class Mail extends Event
{
    protected string $recipient = '';
    protected string $url = '';
    protected string $type = '';
    protected string $name = '';
    protected string $locale = '';
    protected ?Document $team = null;

    public function __construct()
    {
        parent::__construct(Event::MAILS_QUEUE_NAME, Event::MAILS_CLASS_NAME);
    }

    public function setTeam(Document $team): self
    {
        $this->team = $team;

        return $this;
    }

    public function getTeam(): Document
    {
        return $this->team;
    }

    public function setRecipient(string $recipient): self
    {
        $this->recipient = $recipient;

        return $this;
    }

    public function getRecipient(): string
    {
        return $this->recipient;
    }

    public function setUrl(string $url): self
    {
        $this->url = $url;

        return $this;
    }

    public function getURL(): string
    {
        return $this->url;
    }

    public function setType(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setLocale(string $locale): self
    {
        $this->locale = $locale;

        return $this;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function trigger(): string|bool
    {
        return Resque::enqueue($this->queue, $this->class, [
            'project' => $this->project,
            'user' => $this->user,
            'payload' => $this->payload,
            'trigger' => $this->trigger,
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