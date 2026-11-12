<?php

namespace Appwrite\Event\Publisher;

use Appwrite\Event\Message\Maintenance as MaintenanceMessage;
use Utopia\Queue\Publisher\Synchronous as Publisher;
use Utopia\Queue\Queue;

readonly class Maintenance extends Base
{
    public function __construct(
        Publisher $publisher,
        protected Queue $queue,
    ) {
        parent::__construct($publisher);
    }

    public function enqueue(MaintenanceMessage $message): string|bool
    {
        return $this->publish($this->queue, $message);
    }
}
