<?php

namespace Utopia\Query\Schema\Trait;

use Utopia\Query\Builder;
use Utopia\Query\Builder\Statement;

trait MaterializedViews
{
    public function createMaterializedView(string $name, Builder|string $body, ?string $targetTable = null, bool $ifNotExists = true): Statement
    {
        $bindings = [];
        if ($body instanceof Builder) {
            $built = $body->build();
            $bodySql = $built->query;
            $bindings = $built->bindings;
        } else {
            $bodySql = $body;
        }

        $sql = 'CREATE MATERIALIZED VIEW '
            . ($ifNotExists ? 'IF NOT EXISTS ' : '')
            . $this->quote($name)
            . ($targetTable !== null ? ' TO ' . $this->quote($targetTable) : '')
            . ' AS ' . $bodySql;

        return new Statement($sql, $bindings, executor: $this->executor);
    }

    public function dropMaterializedView(string $name, bool $ifExists = true): Statement
    {
        $sql = 'DROP VIEW '
            . ($ifExists ? 'IF EXISTS ' : '')
            . $this->quote($name);

        return new Statement($sql, [], executor: $this->executor);
    }
}
