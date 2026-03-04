<?php

namespace Appwrite\Event\Publisher;

use Appwrite\Event\Message\Base as BaseMessage;
use Utopia\Queue\Publisher as QueuePublisher;
use Utopia\Queue\Queue;

readonly class Base
{
    /**
     * @param QueuePublisher $publisher
     */
    public function __construct(
        protected QueuePublisher $publisher
    ) {
    }

    /**
     * Publish a message to the queue
     *
     * @param Queue $queue
     * @param BaseMessage $message
     * @return string|bool
     */
    public function publish(Queue $queue, BaseMessage $message): string|bool
    {
        $payload = $message->toArray();
        return $this->publisher->enqueue($queue, $payload);
    }

    /**
     * Get the size of a queue
     *
     * @param Queue $queue
     * @param bool $failed
     * @return int
     */
    public function getQueueSize(Queue $queue, bool $failed = false): int
    {
        return $this->publisher->getQueueSize($queue, $failed);
    }
}
