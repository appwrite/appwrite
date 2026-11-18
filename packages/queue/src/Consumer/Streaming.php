<?php

namespace Utopia\Queue\Consumer;

use Utopia\Queue\Consumer;
use Utopia\Queue\Message;
use Utopia\Queue\Queue;

/** Delivers messages through callbacks, incrementally when the connection supports it. */
interface Streaming extends Consumer
{
    /** @param callable(Message): void $onMessage */
    public function receiveEach(Queue $queue, int $timeout, int $n, callable $onMessage): void;
}
