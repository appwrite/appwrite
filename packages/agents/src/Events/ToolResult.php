<?php

namespace Utopia\Agents\Events;

use Utopia\Agents\Event;
use Utopia\Agents\Parts\ToolResult as Outcome;

final readonly class ToolResult implements Event
{
    public function __construct(
        public Outcome $result,
    ) {
    }
}
