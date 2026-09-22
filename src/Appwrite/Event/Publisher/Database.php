<?php

namespace Appwrite\Event\Publisher;

use Appwrite\Event\Message\Database as DatabaseMessage;
use Utopia\Queue\Publisher\Synchronous as Publisher;
use Utopia\Queue\Queue;

readonly class Database extends Base
{
    public function __construct(
        Publisher $publisher,
        protected Queue $queue,
    ) {
        parent::__construct($publisher);
    }

    public function enqueue(DatabaseMessage $message, ?Queue $queue = null): string|bool
    {
        return $this->publish($queue ?? $this->queue, $message);
    }

    public function getSize(bool $failed = false, ?Queue $queue = null): int
    {
        return $this->getQueueSize($queue ?? $this->queue, $failed);
    }

}
