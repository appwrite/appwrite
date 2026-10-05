<?php

namespace Utopia\Query\Builder\Trait;

use Utopia\Query\Query;

trait CrossJoins
{
    #[\Override]
    public function crossJoin(string $table, string $alias = ''): static
    {
        $this->pendingQueries[] = Query::crossJoin($table, $alias);

        return $this;
    }

    #[\Override]
    public function naturalJoin(string $table, string $alias = ''): static
    {
        $this->pendingQueries[] = Query::naturalJoin($table, $alias);

        return $this;
    }
}
