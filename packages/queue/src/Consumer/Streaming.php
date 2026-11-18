<?php

namespace Utopia\Queue\Consumer;

use Utopia\Queue\Consumer;
use Utopia\Queue\Message;
use Utopia\Queue\Queue;

/** Delivers and registers each message before waiting for the rest of a pull. */
interface Streaming extends Consumer
{
    /** @param callable(Message): void $onMessage */
    public function receiveEach(Queue $queue, int $timeout, int $n, callable $onMessage): void;
}
