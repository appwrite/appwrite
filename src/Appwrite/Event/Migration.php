<?php

namespace Appwrite\Event;

use DateTime;
use Resque;
use ResqueScheduler;
use Utopia\Database\Document;

class Migration extends Event
{
    protected string $type = '';
    protected ?Document $migration = null;

    public function __construct()
    {
        parent::__construct(Event::MIGRATIONS_QUEUE_NAME, Event::MIGRATIONS_CLASS_NAME);
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
        return Resque::enqueue($this->queue, $this->class, [
            'project' => $this->project,
            'user' => $this->user,
            'migration' => $this->migration
        ]);
    }

    /**
     * Schedules the migration event and schedules it in the migrations worker queue.
     *
     * @param \DateTime|int $at
     * @return void
     * @throws \Resque_Exception
     * @throws \ResqueScheduler_InvalidTimestampException
     */
    public function schedule(DateTime|int $at): void
    {
        ResqueScheduler::enqueueAt($at, $this->queue, $this->class, [
            'project' => $this->project,
            'user' => $this->user,
            'migration' => $this->migration
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function setUser(Document $user): self
    {
        parent::setUser($user);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function setProject(Document $project): self
    {
        parent::setProject($project);

        return $this;
    }
}
