<?php

namespace Appwrite\Event\Publisher;

use Appwrite\Event\Message\Database as DatabaseMessage;
use Utopia\Database\Document;
use Utopia\DSN\DSN;
use Utopia\Queue\Publisher;
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
        return $this->publish($queue ?? $this->getQueueFromProject($message->project), $message);
    }

    public function getSize(bool $failed = false, ?Queue $queue = null): int
    {
        return $this->getQueueSize($queue ?? $this->queue, $failed);
    }

    private function getQueueFromProject(?Document $project): Queue
    {
        $database = $project?->getAttribute('database', '');
        if (empty($database)) {
            return $this->queue;
        }

        try {
            $dsn = new DSN($database);
        } catch (\InvalidArgumentException) {
            $dsn = new DSN('mysql://' . $database);
        }

        return new Queue($dsn->getHost());
    }
}
