<?php

namespace Utopia\Query\Schema\Trait;

use Utopia\Query\Builder\Statement;
use Utopia\Query\Schema\TriggerEvent;
use Utopia\Query\Schema\TriggerTiming;

trait Triggers
{
    /**
     * Create a trigger.
     *
     * $body is emitted verbatim into the generated DDL and must come from
     * trusted (developer-controlled) source — never from untrusted input.
     */
    public function createTrigger(
        string $name,
        string $table,
        TriggerTiming $timing,
        TriggerEvent $event,
        string $body,
    ): Statement {
        $sql = 'CREATE TRIGGER ' . $this->quote($name)
            . ' ' . $timing->value . ' ' . $event->value
            . ' ON ' . $this->quote($table)
            . ' FOR EACH ROW BEGIN ' . $body . ' END';

        return new Statement($sql, [], executor: $this->executor);
    }

    public function dropTrigger(string $name, ?string $table = null): Statement
    {
        return new Statement('DROP TRIGGER ' . $this->quote($name), [], executor: $this->executor);
    }
}
