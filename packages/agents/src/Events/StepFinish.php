<?php

namespace Utopia\Agents\Events;

use Utopia\Agents\Event;
use Utopia\Agents\Step;

final readonly class StepFinish implements Event
{
    public function __construct(
        public Step $step,
    ) {
    }
}
