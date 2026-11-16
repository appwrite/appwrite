<?php

namespace Appwrite\Event\Publisher;

use Appwrite\Event\Message\Alert as AlertMessage;
use Utopia\Queue\Publisher\Synchronous as Publisher;
use Utopia\Queue\Queue;

readonly class Alert extends Base
{
    public function __construct(
        Publisher $publisher,
        protected Queue $queue,
    ) {
        parent::__construct($publisher);
    }

    public function enqueue(AlertMessage $message): string|bool
    {
        return $this->publish($this->queue, $message);
    }
}
