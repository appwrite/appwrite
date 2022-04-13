<?php

namespace Appwrite\Event;

use Resque;
use Utopia\Database\Document;

class Database extends Event
{
    protected string $type = '';
    protected ?Document $collection = null;
    protected ?Document $document = null;

    public function __construct()
    {
        parent::__construct(Event::DATABASE_QUEUE_NAME, Event::DATABASE_CLASS_NAME);
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

    public function setCollection(Document $collection): self
    {
        $this->collection = $collection;

        return $this;
    }

    public function getCollection(): Document
    {
        return $this->collection;
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
            'user' => $this->user,
            'type' => $this->type,
            'collection' => $this->collection,
            'document' => $this->document,
            'events' => Event::generateEvents($this->getEvent(), $this->getParams())
        ]);
    }
}