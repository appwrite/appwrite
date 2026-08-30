<?php

namespace Appwrite\Event\Publisher;

use Appwrite\Event\Message\Certificate as CertificateMessage;
use Utopia\Queue\Publisher;
use Utopia\Queue\Queue;

readonly class Certificate extends Base
{
    public function __construct(
        Publisher $publisher,
        protected Queue $queue
    ) {
        parent::__construct($publisher);
    }

    public function enqueue(CertificateMessage $message): string|bool
    {
        return $this->publish($this->queue, $message);
    }

    public function getSize(bool $failed = false): int
    {
        return $this->getQueueSize($this->queue, $failed);
    }
}
