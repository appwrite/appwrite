<?php

namespace Utopia\Query\Builder\Trait\ClickHouse;

use Utopia\Query\Builder\ClickHouse\AsofOperator;
use Utopia\Query\Exception\ValidationException;

trait AsofJoins
{
    /**
     * @param  array<string, string>  $equiPairs
     */
    #[\Override]
    public function asofJoin(
        string $table,
        array $equiPairs,
        string $leftInequality,
        AsofOperator $operator,
        string $rightInequality,
        string $alias = '',
    ): static {
        $this->rawJoinClauses[] = $this->buildAsofJoin(
            keyword: 'ASOF JOIN',
            table: $table,
            equiPairs: $equiPairs,
            leftInequality: $leftInequality,
            operator: $operator,
            rightInequality: $rightInequality,
            alias: $alias,
        );

        return $this;
    }

    /**
     * @param  array<string, string>  $equiPairs
     */
    #[\Override]
    public function asofLeftJoin(
        string $table,
        array $equiPairs,
        string $leftInequality,
        AsofOperator $operator,
        string $rightInequality,
        string $alias = '',
    ): static {
        $this->rawJoinClauses[] = $this->buildAsofJoin(
            keyword: 'ASOF LEFT JOIN',
            table: $table,
            equiPairs: $equiPairs,
            leftInequality: $leftInequality,
            operator: $operator,
            rightInequality: $rightInequality,
            alias: $alias,
        );

        return $this;
    }

    /**
     * @param  array<string, string>  $equiPairs
     */
    private function buildAsofJoin(
        string $keyword,
        string $table,
        array $equiPairs,
        string $leftInequality,
        AsofOperator $operator,
        string $rightInequality,
        string $alias,
    ): string {
        if ($equiPairs === []) {
            throw new ValidationException('ASOF JOIN requires at least one equi-join column pair.');
        }

        $tableExpr = $this->quote($table);
        if ($alias !== '') {
            $tableExpr .= ' AS ' . $this->quote($alias);
        }

        $conditions = [];
        foreach ($equiPairs as $left => $right) {
            $conditions[] = $this->resolveAndWrap($left) . ' = ' . $this->resolveAndWrap($right);
        }
        $conditions[] = $this->resolveAndWrap($leftInequality) . ' ' . $operator->value . ' ' . $this->resolveAndWrap($rightInequality);

        return $keyword . ' ' . $tableExpr . ' ON ' . \implode(' AND ', $conditions);
    }
}
