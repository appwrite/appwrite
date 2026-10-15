<?php

namespace Utopia\Query\Builder\Feature;

use Utopia\Query\Builder\JoinType;

interface Joins
{
    public function join(string $table, string $left, string $right, string $operator = '=', string $alias = ''): static;

    public function leftJoin(string $table, string $left, string $right, string $operator = '=', string $alias = ''): static;

    public function rightJoin(string $table, string $left, string $right, string $operator = '=', string $alias = ''): static;

    /**
     * @param  \Closure(\Utopia\Query\Builder\JoinBuilder): void  $callback
     */
    public function joinWhere(string $table, \Closure $callback, JoinType $type = JoinType::Inner, string $alias = ''): static;
}
