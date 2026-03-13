<?php

namespace Appwrite\Event\Publisher;

use Appwrite\Event\Message\Base as BaseMessage;
use Utopia\Queue\Publisher;
use Utopia\Queue\Queue;

readonly class Base
{
    public function __construct(
        protected Publisher $publisher
    ) {
    }

    /**
     * Publish a message to the queue
     */
    public function publish(Queue $queue, BaseMessage $message): string|bool
    {
        $payload = $message->toArray();

        return $this->publisher->enqueue($queue, $payload);
    }

    /**
     * Get the size of a queue
     */
    public function getQueueSize(Queue $queue, bool $failed = false): int
    {
        return $this->publisher->getQueueSize($queue, $failed);
    }
}
