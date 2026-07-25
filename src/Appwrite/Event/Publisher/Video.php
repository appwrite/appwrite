<?php

namespace Appwrite\Event\Publisher;

use Appwrite\Event\Message\Video as VideoMessage;
use Utopia\Queue\Publisher;
use Utopia\Queue\Queue;

readonly class Video extends Base
{
    public function __construct(
        Publisher $publisher,
        protected Queue $queue
    ) {
        parent::__construct($publisher);
    }

    public function enqueue(VideoMessage $message): string|bool
    {
        return $this->publish($this->queue, $message);
    }

    public function getSize(bool $failed = false): int
    {
        return $this->getQueueSize($this->queue, $failed);
    }
}
