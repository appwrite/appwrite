<?php

namespace Utopia\Agents\Events;

use Utopia\Agents\Event;

final readonly class ReasoningDelta implements Event
{
    public function __construct(
        public string $delta,
    ) {
    }
}
