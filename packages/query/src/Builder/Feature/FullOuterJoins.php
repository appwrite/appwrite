<?php

namespace Utopia\Query\Builder\Feature;

interface FullOuterJoins
{
    public function fullOuterJoin(string $table, string $left, string $right, string $operator = '=', string $alias = ''): static;
}
