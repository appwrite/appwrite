<?php

namespace Utopia\Query\Builder\Trait;

use Utopia\Query\Builder\Statement;
use Utopia\Query\Query;

trait Deletes
{
    #[\Override]
    public function delete(): Statement
    {
        $this->bindings = [];
        $this->validateTable();

        $grouped = Query::groupByType($this->pendingQueries);

        $parts = ['DELETE FROM ' . $this->quote($this->table)];

        $this->compileWhereClauses($parts, $grouped);

        $this->compileOrderAndLimit($parts, $grouped);

        return new Statement(\implode(' ', $parts), $this->getBindingValues(), executor: $this->executor);
    }
}
