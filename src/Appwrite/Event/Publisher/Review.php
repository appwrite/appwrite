<?php

namespace Appwrite\Event\Publisher;

use Appwrite\Event\Message\Review as ReviewMessage;
use Appwrite\Event\Message\ReviewChat as ReviewChatMessage;
use Utopia\Queue\Publisher\Synchronous as Publisher;
use Utopia\Queue\Queue;

readonly class Review extends Base
{
    public function __construct(
        Publisher $publisher,
        protected Queue $queue
    ) {
        parent::__construct($publisher);
    }

    public function enqueue(ReviewMessage|ReviewChatMessage $message, ?Queue $queue = null): string|bool
    {
        return $this->publish($queue ?? $this->queue, $message);
    }
}
