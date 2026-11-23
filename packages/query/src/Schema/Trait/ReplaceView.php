<?php

namespace Utopia\Query\Schema\Trait;

use Utopia\Query\Builder;
use Utopia\Query\Builder\Statement;

trait ReplaceView
{
    public function createOrReplaceView(string $name, Builder $query): Statement
    {
        $result = $query->build();
        $sql = 'CREATE OR REPLACE VIEW ' . $this->quote($name) . ' AS ' . $result->query;

        return new Statement($sql, $result->bindings, executor: $this->executor);
    }
}
