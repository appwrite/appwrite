<?php

namespace Appwrite\Event\Publisher;

use Appwrite\Event\Message\Jobs as JobsMessage;
use Utopia\Queue\Publisher;
use Utopia\Queue\Queue;

readonly class Jobs extends Base
{
    public function __construct(
        Publisher $publisher,
        protected Queue $queue
    ) {
        parent::__construct($publisher);
    }

    public function enqueue(JobsMessage $message, ?Queue $queue = null): string|bool
    {
        return $this->publish($queue ?? $this->queue, $message);
    }
}
