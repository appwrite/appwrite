<?php

namespace Appwrite\Event\Publisher;

use Appwrite\Event\Message\Base as BaseMessage;
use Appwrite\Event\Message\Database as DatabaseMessage;
use Utopia\Database\Document;
use Utopia\DSN\DSN;
use Utopia\Queue\Queue;

/** @extends Base<DatabaseMessage> */
readonly class Database extends Base
{
    protected function dispatch(BaseMessage $message, ?Queue $queue, bool $background): string|bool
    {
        return parent::dispatch($message, $queue ?? $this->getQueueFromProject($message->project), $background);
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
