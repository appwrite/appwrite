<?php

namespace Appwrite\Event;

use Resque;
use Utopia\Database\Document;

class Delete extends Event
{
    protected string $type = '';
    protected ?Document $document = null;
    protected ?string $resource = null;
    protected ?string $datetime = null;
    protected ?string $datetime1d = null;
    protected ?string $datetime30m = null;

    public function __construct()
    {
        parent::__construct(Event::DELETE_QUEUE_NAME, Event::DELETE_CLASS_NAME);
    }

    /**
     * Sets the type for the delete event (use the constants starting with DELETE_TYPE_*).
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
     * Returns the set type for the delete event.
     *
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * set Datetime.
     *
     * @param string $datetime
     * @return self
     */
    public function setDatetime(string $datetime): self
    {
        $this->datetime = $datetime;
        return $this;
    }

    /**
     * Set datetime for 1 day interval.
     *
     * @param string $datetime
     * @return self
     */
    public function setDatetime1d(string $datetime): self
    {
        $this->datetime1d = $datetime;
        return $this;
    }

    /**
     * Sets datetime for 30m interval.
     *
     * @param string $datetime
     * @return self
     */
    public function setDatetime30m(string $datetime): self
    {
        $this->datetime30m = $datetime;
        return $this;
    }

    /**
     * Sets the document for the delete event.
     *
     * @param Document $document
     * @return self
     */
    public function setDocument(Document $document): self
    {
        $this->document = $document;

        return $this;
    }

    /**
     * Returns the resource for the delete event.
     *
     * @return string
     */
    public function getResource(): string
    {
        return $this->resource;
    }

    /**
     * Sets the resource for the delete event.
     *
     * @param string $resource
     * @return self
     */
    public function setResource(string $resource): self
    {
        $this->resource = $resource;

        return $this;
    }

    /**
     * Returns the set document for the delete event.
     *
     * @return null|Document
     */
    public function getDocument(): ?Document
    {
        return $this->document;
    }


    /**
     * Executes this event and sends it to the deletes worker.
     *
     * @return string|bool
     * @throws \InvalidArgumentException
     */
    public function trigger(): string|bool
    {
        return Resque::enqueue($this->queue, $this->class, [
            'project' => $this->project,
            'type' => $this->type,
            'document' => $this->document,
            'resource' => $this->resource,
            'datetime' => $this->datetime,
            'datetime1d' => $this->datetime1d,
            'datetime30m' => $this->datetime30m,
        ]);
    }
}
