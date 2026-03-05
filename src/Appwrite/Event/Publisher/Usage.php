<?php

namespace Appwrite\Event\Publisher;

use Appwrite\Event\Message\Usage as UsageMessage;
use Utopia\Queue\Publisher;
use Utopia\Queue\Queue;

readonly class Usage extends Base
{
    public function __construct(
        Publisher $publisher,
        protected Queue $queue
    ) {
        parent::__construct($publisher);
    }

    /**
     * Enqueue a usage message
     */
    public function enqueue(UsageMessage $message): string|bool
    {
        return $this->publish($this->queue, $message);
    }

    /**
     * Get the size of the usage queue
     */
    public function getSize(bool $failed = false): int
    {
        return $this->getQueueSize($this->queue, $failed);
    }
}
