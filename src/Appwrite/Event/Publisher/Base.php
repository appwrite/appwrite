<?php

namespace Appwrite\Event\Publisher;

use Appwrite\Event\Message\Base as BaseMessage;
use Utopia\Queue\Broker\Background;
use Utopia\Queue\Publisher\Asynchronous;
use Utopia\Queue\Publisher\Synchronous as Publisher;
use Utopia\Queue\Queue;

/**
 * @template TMessage of BaseMessage
 */
readonly class Base
{
    public function __construct(
        protected Publisher $publisher,
        protected Queue $queue,
    ) {
    }

    /**
     * Wait for the broker to accept the message.
     *
     * @param TMessage $message
     */
    public function publish(BaseMessage $message, ?Queue $queue = null): string|bool
    {
        return $this->dispatch($message, $queue, false);
    }

    /**
     * Accept background delivery when supported, otherwise publish directly.
     *
     * @param TMessage $message
     */
    public function enqueue(BaseMessage $message, ?Queue $queue = null): string|bool
    {
        return $this->dispatch($message, $queue, true);
    }

    /** @param TMessage $message */
    protected function dispatch(BaseMessage $message, ?Queue $queue, bool $background): string|bool
    {
        $queue ??= $this->queue;
        $payload = $message->toArray();

        if ($background && $this->publisher instanceof Asynchronous) {
            $this->publisher->enqueue($queue, $payload);

            return true;
        }

        return $this->publisher->publish($queue, $payload);
    }

    public function getSize(bool $failed = false, ?Queue $queue = null): int
    {
        return $this->publisher->getQueueSize($queue ?? $this->queue, $failed);
    }

    public function start(): void
    {
        if ($this->publisher instanceof Background) {
            $this->publisher->start();
        }
    }

    public function shutdown(): void
    {
        if ($this->publisher instanceof Background) {
            $this->publisher->shutdown();
        }
    }
}
