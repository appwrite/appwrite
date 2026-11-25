<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

namespace Utopia\Query\Builder\Feature;

interface Joins
{
    public function join(string $table, string $left, string $right, string $operator = '=', string $alias = ''): static;
    public function leftJoin(string $table, string $left, string $right, string $operator = '=', string $alias = ''): static;
    public function rightJoin(string $table, string $left, string $right, string $operator = '=', string $alias = ''): static;
    /**
     * @param  \Closure(\Utopia\Query\Builder\JoinBuilder): void  $callback
     */
    public function joinWhere(string $table, \Closure $callback, \Utopia\Query\Builder\JoinType $type = \Utopia\Query\Builder\JoinType::Inner, string $alias = ''): static;
}
