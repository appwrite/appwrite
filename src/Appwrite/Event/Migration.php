<?php

namespace Appwrite\Event;

use Utopia\Database\Document;
use Utopia\Queue\Client;
use Utopia\Queue\Connection;

class Migration extends Event
{
    protected string $type = '';
    protected ?Document $migration = null;
    private ?Document $backup;

    public function __construct(protected Connection $connection)
    {
        parent::__construct($connection);

        $this
            ->setQueue(Event::MIGRATIONS_QUEUE_NAME)
            ->setClass(Event::MIGRATIONS_CLASS_NAME);
    }

    /**
     * Sets migration document for the migration event.
     *
     * @param Document $migration
     * @return self
     */
    public function setMigration(Document $migration): self
    {
        $this->migration = $migration;

        return $this;
    }


    /**
     * Sets backup.
     *
     * @param Document $backup
     * @return self
     */
    public function setBackup(Document $backup): self
    {
        $this->backup = $backup;

        return $this;
    }

    /**
     * Returns set migration document for the function event.
     *
     * @return null|Document
     */
    public function getMigration(): ?Document
    {
        return $this->migration;
    }

    /**
     * Sets migration type for the migration event.
     *
     * @param string $type
     *
     * @return self
     */
    public function setType(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    /**
     * Returns set migration type for the migration event.
     *
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Executes the migration event and sends it to the migrations worker.
     *
     * @return string|bool
     * @throws \InvalidArgumentException
     */
    public function trigger(): string|bool
    {

        $client = new Client($this->queue, $this->connection);

        return $client->enqueue([
            'project' => $this->project,
            'user' => $this->user,
            'backup' => $this->backup,
            'migration' => $this->migration
        ]);
    }
}
