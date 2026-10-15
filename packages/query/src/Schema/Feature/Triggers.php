<?php

namespace Utopia\Query\Schema\Feature;

use Utopia\Query\Builder\Statement;
use Utopia\Query\Schema\TriggerEvent;
use Utopia\Query\Schema\TriggerTiming;

interface Triggers
{
    public function createTrigger(
        string $name,
        string $table,
        TriggerTiming $timing,
        TriggerEvent $event,
        string $body,
    ): Statement;

    /**
     * Drop a trigger. PostgreSQL requires $table; MySQL/SQLite ignore it.
     */
    public function dropTrigger(string $name, ?string $table = null): Statement;
}
