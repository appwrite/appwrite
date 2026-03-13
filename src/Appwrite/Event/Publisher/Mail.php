<?php

namespace Appwrite\Event\Publisher;

use Appwrite\Event\Message\Mail as Message;
use Utopia\Queue\Publisher;
use Utopia\Queue\Queue;

readonly class Mail extends Base
{
    public function __construct(
        Publisher $publisher,
        protected Queue $queue
    ) {
        parent::__construct($publisher);
    }

    public function enqueue(Message $message): string|bool
    {
        return $this->publish($this->queue, $message);
    }

    public function getSize(bool $failed = false): int
    {
        return $this->getQueueSize($this->queue, $failed);
    }
}
