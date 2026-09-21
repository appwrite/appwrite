<?php

namespace Appwrite\Event\Publisher;

use Appwrite\Event\Message\Base as BaseMessage;
use Utopia\Queue\Publisher\Synchronous as Publisher;
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

        return $this->publisher->publish($queue, $payload);
    }

    /**
     * Publish many messages to the queue in one round trip
     *
     * @param array<BaseMessage> $messages
     */
    public function publishMany(Queue $queue, array $messages): bool
    {
        if ($messages === []) {
            return false;
        }

        $payloads = [];
        foreach ($messages as $message) {
            $payloads[] = $message->toArray();
        }

        return $this->publisher->enqueueMany($queue, $payloads);
    }

    /**
     * Get the size of a queue
     */
    public function getQueueSize(Queue $queue, bool $failed = false): int
    {
        return $this->publisher->getQueueSize($queue, $failed);
    }
}
