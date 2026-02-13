<?php

namespace Appwrite\Bus;

use Utopia\Bus\Event;

class ExecutionCompleted implements Event
{
    /**
     * @param array<string, mixed> $execution
     * @param array<string, mixed> $project
     * @param array<string, mixed> $spec
     * @param array<string, mixed> $resource
     */
    public function __construct(
        public readonly array $execution,
        public readonly array $project,
        public readonly array $spec = [],
        public readonly array $resource = [],
    ) {
    }
}
