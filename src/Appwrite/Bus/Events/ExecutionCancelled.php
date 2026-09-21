<?php

namespace Appwrite\Bus\Events;

use Utopia\Bus\Event;

class ExecutionCancelled implements Event
{
    /**
     * @param array<string, mixed> $execution
     * @param array<string, mixed> $project
     */
    public function __construct(
        public readonly array $execution,
        public readonly array $project,
    ) {
    }
}
