<?php

namespace Utopia\Query\Builder\Trait;

trait StringAggregates
{
    /**
     * @param  list<string>|null  $orderBy
     */
    #[\Override]
    public function groupConcat(string $column, string $separator = ',', string $alias = '', ?array $orderBy = null): static
    {
        $orderByFragment = '';
        if ($orderBy !== null && $orderBy !== []) {
            $cols = [];
            foreach ($orderBy as $col) {
                if (\str_starts_with($col, '-')) {
                    $cols[] = $this->resolveAndWrap(\substr($col, 1)) . ' DESC';
                } else {
                    $cols[] = $this->resolveAndWrap($col) . ' ASC';
                }
            }
            $orderByFragment = 'ORDER BY ' . \implode(', ', $cols);
        }

        $expr = $this->groupConcatExpr($this->resolveAndWrap($column), $orderByFragment);
        if ($alias !== '') {
            $expr .= ' AS ' . $this->quote($alias);
        }

        return $this->select($expr, [$separator]);
    }

    #[\Override]
    public function jsonArrayAgg(string $column, string $alias = ''): static
    {
        $expr = $this->jsonArrayAggExpr($this->resolveAndWrap($column));
        if ($alias !== '') {
            $expr .= ' AS ' . $this->quote($alias);
        }

        return $this->select($expr);
    }

    #[\Override]
    public function jsonObjectAgg(string $keyColumn, string $valueColumn, string $alias = ''): static
    {
        $expr = $this->jsonObjectAggExpr(
            $this->resolveAndWrap($keyColumn),
            $this->resolveAndWrap($valueColumn),
        );
        if ($alias !== '') {
            $expr .= ' AS ' . $this->quote($alias);
        }

        return $this->select($expr);
    }

    /**
     * Build the dialect-specific GROUP_CONCAT / STRING_AGG expression.
     * Receives the already-wrapped column identifier and a compiled
     * `ORDER BY ...` fragment (empty string when no ordering requested).
     * Implementations must include the single `?` placeholder where the
     * separator literal should bind.
     */
    abstract protected function groupConcatExpr(string $column, string $orderBy): string;

    abstract protected function jsonArrayAggExpr(string $column): string;

    abstract protected function jsonObjectAggExpr(string $keyColumn, string $valueColumn): string;
}
