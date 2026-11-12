<?php

namespace Appwrite\Bus\Events;

use Utopia\Bus\Event;

class RuleVerified implements Event
{
    /**
     * @param array<string, mixed> $rule
     * @param array<string, mixed> $project
     */
    public function __construct(
        public readonly array $rule,
        public readonly array $project,
    ) {
    }
}
