<?php

namespace Appwrite\Event;

use Resque;

class Encrypt extends Event
{
    protected string $type = '';

    public function __construct()
    {
        parent::__construct(Event::ENCRYPTION_QUEUE_NAME, Event::ENCRYPTION_CLASS_NAME);
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
        ]);
    }
}
