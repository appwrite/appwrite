<?php

namespace Appwrite\Event\Publisher;

use Appwrite\Event\Message\Usage as UsageMessage;
use Utopia\Queue\Queue;
use Utopia\Queue\Publisher;

readonly class Usage extends Base
{
    /**
     * @param Publisher $publisher
     * @param Queue $queue
     */
    public function __construct(
        Publisher $publisher,
        protected Queue $queue
    ) {
        parent::__construct($publisher);
    }

    /**
     * Enqueue a usage message
     *
     * @param UsageMessage $message
     * @return string|bool
     */
    public function enqueue(UsageMessage $message): string|bool
    {
        return $this->publish($this->queue, $message);
    }

    /**
     * Get the size of the usage queue
     *
     * @param bool $failed
     * @return int
     */
    public function getSize(bool $failed = false): int
    {
        return $this->getQueueSize($this->queue, $failed);
    }
}
