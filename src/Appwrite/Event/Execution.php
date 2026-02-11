<?php

namespace Appwrite\Event;

use Utopia\Database\Document;
use Utopia\Queue\Publisher;

class Execution extends Event
{
    protected ?Document $execution = null;

    public function __construct(protected Publisher $publisher)
    {
        parent::__construct($publisher);

        $this
            ->setQueue(Event::EXECUTIONS_QUEUE_NAME)
            ->setClass(Event::EXECUTIONS_CLASS_NAME);
    }

    /**
     * Sets execution document for the execution event.
     *
     * @param Document $execution
     * @return self
     */
    public function setExecution(Document $execution): self
    {
        $this->execution = $execution;

        return $this;
    }

    /**
     * Returns set execution document for the execution event.
     *
     * @return null|Document
     */
    public function getExecution(): ?Document
    {
        return $this->execution;
    }

    /**
     * Prepare payload for the execution event.
     *
     * @return array
     */
    protected function preparePayload(): array
    {
        return [
            'project' => $this->project,
            'execution' => $this->execution,
        ];
    }
}
