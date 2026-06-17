<?php

namespace Appwrite\Event\Publisher;

use Appwrite\Event\Message\Execution as ExecutionMessage;
use Appwrite\Event\Message\Executions as ExecutionsMessage;
use Utopia\Queue\Publisher;
use Utopia\Queue\Queue;

readonly class Execution extends Base
{
    public function __construct(
        Publisher $publisher,
        protected Queue $queue
    ) {
        parent::__construct($publisher);
    }

    public function enqueue(ExecutionMessage|ExecutionsMessage $message): string|bool
    {
        return $this->publish($this->queue, $message);
    }

    public function getSize(bool $failed = false): int
    {
        return $this->getQueueSize($this->queue, $failed);
    }
}
