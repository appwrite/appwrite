<?php

namespace Appwrite\Event\Publisher;

use Appwrite\Event\Message\Screenshot as ScreenshotMessage;
use Utopia\Queue\Publisher;
use Utopia\Queue\Queue;

readonly class Screenshot extends Base
{
    public function __construct(
        Publisher $publisher,
        protected Queue $queue
    ) {
        parent::__construct($publisher);
    }

    public function enqueue(ScreenshotMessage $message): string|bool
    {
        return $this->publish($this->queue, $message);
    }

    public function getSize(bool $failed = false): int
    {
        return $this->getQueueSize($this->queue, $failed);
    }
}
