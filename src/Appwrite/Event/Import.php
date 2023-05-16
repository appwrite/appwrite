<?php

namespace Appwrite\Event;

use DateTime;
use Resque;
use ResqueScheduler;
use Utopia\Database\Document;

class Import extends Event
{
    protected string $type = '';
    protected ?Document $import = null;

    public function __construct()
    {
        parent::__construct(Event::IMPORTS_QUEUE_NAME, Event::IMPORTS_CLASS_NAME);
    }

    /**
     * Sets import document for the import event.
     *
     * @param Document $import
     * @return self
     */
    public function setImport(Document $import): self
    {
        $this->import = $import;

        return $this;
    }

    /**
     * Returns set import document for the function event.
     *
     * @return null|Document
     */
    public function getImport(): ?Document
    {
        return $this->import;
    }

    /**
     * Sets import type for the import event.
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
     * Returns set import type for the import event.
     *
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Executes the import event and sends it to the imports worker.
     *
     * @return string|bool
     * @throws \InvalidArgumentException
     */
    public function trigger(): string|bool
    {
        return Resque::enqueue($this->queue, $this->class, [
            'project' => $this->project,
            'user' => $this->user,
            'import' => $this->import
        ]);
    }

    /**
     * Schedules the import event and schedules it in the imports worker queue.
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
            'import' => $this->import
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
