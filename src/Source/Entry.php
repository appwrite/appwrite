<?php

declare(strict_types=1);

namespace Utopia\Schedule\Source;

use Utopia\Schedule\Trigger;

/**
 * What `make` returns for a {@see Row}: the trigger that decides when it runs, and an
 * opaque payload handed to the dispatch handler with every occurrence.
 */
final readonly class Entry
{
    public function __construct(
        public Trigger $trigger,
        public mixed $payload = null,
    ) {}
}
