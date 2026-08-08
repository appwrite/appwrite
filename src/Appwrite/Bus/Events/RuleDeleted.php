<?php

namespace Appwrite\Bus\Events;

use Utopia\Bus\Event;

class RuleDeleted implements Event
{
    /**
     * @param array<string, mixed> $rule
     */
    public function __construct(
        public readonly array $rule,
    ) {
    }
}
