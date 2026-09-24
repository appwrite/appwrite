<?php

namespace Utopia\Query\Schema\Trait;

use Utopia\Query\Builder;
use Utopia\Query\Builder\Statement;

trait Views
{
    public function createView(string $name, Builder $query): Statement
    {
        $result = $query->build();
        $sql = 'CREATE VIEW ' . $this->quote($name) . ' AS ' . $result->query;

        return new Statement($sql, $result->bindings, executor: $this->executor);
    }

    public function dropView(string $name): Statement
    {
        return new Statement('DROP VIEW ' . $this->quote($name), [], executor: $this->executor);
    }
}
