<?php

namespace Utopia\Query\Schema\Trait;

use Utopia\Query\Builder\Statement;

trait RenameIndex
{
    public function renameIndex(string $table, string $from, string $to): Statement
    {
        return new Statement(
            'ALTER TABLE ' . $this->quote($table) . ' RENAME INDEX ' . $this->quote($from) . ' TO ' . $this->quote($to),
            [],
            executor: $this->executor,
        );
    }
}
