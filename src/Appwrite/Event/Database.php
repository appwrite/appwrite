<?php

namespace Appwrite\Event;

use Utopia\Database\Document;
use Utopia\DSN\DSN;
use Utopia\Queue\Publisher;
use Utopia\System\System;

class Database extends Event
{
    protected string $type = '';
    protected ?Document $database = null;

    // tables api
    protected ?Document $row = null;
    protected ?Document $table = null;

    // collections api
    protected ?Document $document = null;
    protected ?Document $collection = null;


    public function __construct(protected Publisher $publisher)
    {
        parent::__construct($publisher);

        $this->setClass(System::getEnv('_APP_DATABASE_CLASS_NAME', Event::DATABASE_CLASS_NAME));
    }

    /**
     * Sets the type for this database event (use the constants starting with DATABASE_TYPE_*).
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
     * Returns the set type for the database event.
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Set the database for this event
     *
     * @param Document $database
     * @return self
     */
    public function setDatabase(Document $database): self
    {
        $this->database = $database;
        return $this;
    }

    /**
     * Returns set database for this event.
     *
     * @return null|Document
     */
    public function getDatabase(): ?Document
    {
        return $this->database;
    }

    /**
     * Set the table for this database event.
     *
     * @param Document $table
     * @return self
     */
    public function setTable(Document $table): self
    {
        $this->table = $table;

        return $this;
    }

    /**
     * Returns set collection for this event.
     *
     * @return null|Document
     */
    public function getCollection(): ?Document
    {
        return $this->collection;
    }

    /**
     * Returns set document for this database event.
     * @return null|Document
     */
    public function getDocument(): ?Document
    {
        return $this->document;
    }

    public function setProject(Document $project): static
    {
        $database = $project->getAttribute('database');
        if (!empty($database)) {
            try {
                $dsn = new DSN($database);
            } catch (\InvalidArgumentException) {
                // TODO: Temporary until all projects are using shared tables
                $dsn = new DSN("mysql://$database");
            }
            $this->queue = $dsn->getHost();
        }

        return parent::setProject($project);
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
            'user' => $this->user,
            'type' => $this->type,
            'table' => $this->table,
            'row' => $this->row,
            'collection' => $this->collection,
            'document' => $this->document,
            'database' => $this->database,
            'events' => Event::generateEvents($this->getEvent(), $this->getParams())
        ];
    }
}
