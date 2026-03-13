<?php

namespace Appwrite\Event\Publisher;

use Appwrite\Event\Message\Mail as Message;
use Utopia\Console;
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
        try {
            return $this->publish($this->queue, $message);
        } catch (\Throwable $th) {
            Console::error('[Mail] Failed to publish mail message: ' . $th->getMessage());
            return false;
        }
    }

    public function getSize(bool $failed = false): int
    {
        return $this->getQueueSize($this->queue, $failed);
    }
}
