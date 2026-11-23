<?php

namespace Utopia\Query\Builder\Feature\ClickHouse;

use Utopia\Query\Builder\ClickHouse\AsofOperator;

interface AsofJoins
{
    /**
     * ClickHouse ASOF JOIN — joins each left row to the nearest right row that
     * satisfies the inequality, matched by the equi-join columns.
     *
     * ClickHouse requires ≥1 equi-join pair plus exactly one inequality condition.
     *
     * @param  array<string, string>  $equiPairs  Equi-join columns as ['leftCol' => 'rightCol']. Must be non-empty.
     * @param  string  $leftInequality  Left column of the inequality (e.g. 'trades.ts').
     * @param  AsofOperator  $operator  Inequality operator.
     * @param  string  $rightInequality  Right column of the inequality (e.g. 'quotes.ts').
     */
    public function asofJoin(
        string $table,
        array $equiPairs,
        string $leftInequality,
        AsofOperator $operator,
        string $rightInequality,
        string $alias = '',
    ): static;

    /**
     * ClickHouse ASOF LEFT JOIN — left-join variant; unmatched left rows appear
     * with NULLs on the right side.
     *
     * @param  array<string, string>  $equiPairs
     */
    public function asofLeftJoin(
        string $table,
        array $equiPairs,
        string $leftInequality,
        AsofOperator $operator,
        string $rightInequality,
        string $alias = '',
    ): static;
}
