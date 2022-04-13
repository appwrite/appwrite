<?php

namespace Appwrite\Event;

use Resque;
use Utopia\Database\Document;

class Delete extends Event
{
    protected string $type = '';
    protected ?Document $document = null;

    public function __construct()
    {
        parent::__construct(Event::DELETE_QUEUE_NAME, Event::DELETE_CLASS_NAME);
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

    public function setDocument(Document $document): self
    {
        $this->document = $document;

        return $this;
    }

    public function getDocument(): Document
    {
        return $this->document;
    }

    public function trigger(): string|bool
    {
        return Resque::enqueue($this->queue, $this->class, [
            'project' => $this->project,
            'type' => $this->type,
            'document' => $this->document,
        ]);
    }
}