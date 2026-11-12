<?php

namespace Utopia\Agents\Events;

use Utopia\Agents\Event;
use Utopia\Agents\Result;

final readonly class Finish implements Event
{
    public function __construct(
        public Result $result,
    ) {
    }
}
