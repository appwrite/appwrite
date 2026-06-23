<?php

namespace Appwrite\Event\Publisher;

use Appwrite\Event\Message\Audit as AuditMessage;
use Utopia\Console;
use Utopia\Queue\Publisher;
use Utopia\Queue\Queue;

readonly class Audit extends Base
{
    public function __construct(
        Publisher $publisher,
        protected Queue $queue
    ) {
        parent::__construct($publisher);
    }

    public function enqueue(AuditMessage $message): string|bool
    {
        // Audit delivery is best-effort and should never fail the request lifecycle.
        try {
            return $this->publish($this->queue, $message);
        } catch (\Throwable $th) {
            Console::error('[Audit] Failed to publish audit message: ' . $th->getMessage());

            return false;
        }
    }

    public function getSize(bool $failed = false): int
    {
        return $this->getQueueSize($this->queue, $failed);
    }
}
