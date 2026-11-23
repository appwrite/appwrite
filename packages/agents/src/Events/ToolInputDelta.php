<?php

namespace Utopia\Agents\Events;

use Utopia\Agents\Event;

/**
 * A fragment of the JSON arguments of a tool call still being generated.
 */
final readonly class ToolInputDelta implements Event
{
    public function __construct(
        public string $id,
        public string $name,
        public string $delta,
    ) {
    }
}
