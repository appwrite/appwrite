<?php

namespace Utopia\Query\Builder\Trait;

use Utopia\Query\Method;
use Utopia\Query\Query;

trait CrossJoins
{
    #[\Override]
    public function crossJoin(string $table, string $alias = ''): static
    {
        $this->pendingQueries[] = new Query(Method::CrossJoin, $table, [], $alias);

        return $this;
    }

    #[\Override]
    public function naturalJoin(string $table, string $alias = ''): static
    {
        $this->pendingQueries[] = new Query(Method::NaturalJoin, $table, [], $alias);

        return $this;
    }
}
