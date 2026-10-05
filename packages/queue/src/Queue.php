<?php

declare(strict_types=1);

namespace Utopia\Queue;

readonly class Queue
{
    public function __construct(
        public string $name,
        public string $namespace = 'utopia-queue',
        public int $jobTtl = 0,
        public int $keyTtl = 86_400,
    ) {
        if ($this->name === '' || $this->name === '0') {
            throw new \InvalidArgumentException('Cannot create queue with empty name.');
        }
        if ($this->keyTtl < 1) {
            throw new \InvalidArgumentException('Cannot create queue with a key TTL below one second.');
        }
    }
}
