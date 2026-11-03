<?php

namespace Utopia\Agents\Events;

use Utopia\Agents\Event;
use Utopia\Agents\Parts\ToolCall as Call;

final readonly class ToolCall implements Event
{
    public function __construct(
        public Call $call,
    ) {
    }
}
