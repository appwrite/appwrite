<?php

namespace Appwrite\Event\Publisher;

use Appwrite\Event\Message\Messaging as MessagingMessage;
use Utopia\Queue\Publisher;
use Utopia\Queue\Queue;

readonly class Messaging extends Base
{
    public function __construct(
        Publisher $publisher,
        protected Queue $queue
    ) {
        parent::__construct($publisher);
    }

    public function enqueue(MessagingMessage $message, ?Queue $queue = null): string|bool
    {
        return $this->publish($queue ?? $this->queue, $message);
    }

    public function getSize(bool $failed = false, ?Queue $queue = null): int
    {
        return $this->getQueueSize($queue ?? $this->queue, $failed);
    }
}
