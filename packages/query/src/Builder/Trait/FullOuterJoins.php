<?php

namespace Utopia\Query\Builder\Trait;

use Utopia\Query\Method;
use Utopia\Query\Query;

trait FullOuterJoins
{
    #[\Override]
    public function fullOuterJoin(string $table, string $left, string $right, string $operator = '=', string $alias = ''): static
    {
        $this->pendingQueries[] = new Query(Method::FullOuterJoin, $table, [Query::on($left, $right, $operator)], $alias);

        return $this;
    }
}
