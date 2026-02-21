<?php

namespace Appwrite\Event;

use Utopia\Database\Document;
use Utopia\Queue\Publisher;

class Delete extends Event
{
    protected string $type = '';
    protected ?Document $document = null;
    protected ?string $resourceType = null;
    protected ?string $resource = null;
    protected ?string $datetime = null;
    protected ?string $hourlyUsageRetentionDatetime = null;


    public function __construct(protected Publisher $publisher)
    {
        parent::__construct($publisher);

        $this
            ->setQueue(Event::DELETE_QUEUE_NAME)
            ->setClass(Event::DELETE_CLASS_NAME);
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
     * Sets datetime for 1h interval.
     *
     * @param string $datetime
     * @return self
     */
    public function setUsageRetentionHourlyDateTime(string $datetime): self
    {
        $this->hourlyUsageRetentionDatetime = $datetime;
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
     * Sets the resource type for the delete event.
     *
     * @param string $resourceType
     * @return self
     */
    public function setResourceType(string $resourceType): self
    {
        $this->resourceType = $resourceType;

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
     * Prepare the payload for the event
     *
     * @return array
     */
    protected function preparePayload(): array
    {
        return [
            'project' => $this->project,
            'type' => $this->type,
            'document' => $this->document,
            'resource' => $this->resource,
            'resourceType' => $this->resourceType,
            'datetime' => $this->datetime,
            'hourlyUsageRetentionDatetime' => $this->hourlyUsageRetentionDatetime
        ];
    }
}
