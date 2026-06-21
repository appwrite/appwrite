<?php

namespace Appwrite\Event\Publisher;

use Appwrite\Event\Message\Func as FunctionMessage;
use Utopia\Queue\Publisher;
use Utopia\Queue\Queue;

readonly class Func extends Base
{
    public function __construct(
        Publisher $publisher,
        protected Queue $queue,
    ) {
        parent::__construct($publisher);
    }

    public function enqueue(FunctionMessage $message, ?Queue $queue = null): string|bool
    {
        return $this->publish($queue ?? $this->queue, $message);
    }

    public function getSize(bool $failed = false, ?Queue $queue = null): int
    {
        return $this->getQueueSize($queue ?? $this->queue, $failed);
    }
}
