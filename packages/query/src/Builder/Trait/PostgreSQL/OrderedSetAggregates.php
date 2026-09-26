<?php

namespace Utopia\Query\Builder\Trait\PostgreSQL;

trait OrderedSetAggregates
{
    #[\Override]
    public function arrayAgg(string $column, string $alias = ''): static
    {
        $expr = 'ARRAY_AGG(' . $this->resolveAndWrap($column) . ')';
        if ($alias !== '') {
            $expr .= ' AS ' . $this->quote($alias);
        }

        return $this->select($expr);
    }

    #[\Override]
    public function boolAnd(string $column, string $alias = ''): static
    {
        $expr = 'BOOL_AND(' . $this->resolveAndWrap($column) . ')';
        if ($alias !== '') {
            $expr .= ' AS ' . $this->quote($alias);
        }

        return $this->select($expr);
    }

    #[\Override]
    public function boolOr(string $column, string $alias = ''): static
    {
        $expr = 'BOOL_OR(' . $this->resolveAndWrap($column) . ')';
        if ($alias !== '') {
            $expr .= ' AS ' . $this->quote($alias);
        }

        return $this->select($expr);
    }

    #[\Override]
    public function every(string $column, string $alias = ''): static
    {
        $expr = 'EVERY(' . $this->resolveAndWrap($column) . ')';
        if ($alias !== '') {
            $expr .= ' AS ' . $this->quote($alias);
        }

        return $this->select($expr);
    }

    #[\Override]
    public function mode(string $column, string $alias = ''): static
    {
        $expr = 'MODE() WITHIN GROUP (ORDER BY ' . $this->resolveAndWrap($column) . ')';
        if ($alias !== '') {
            $expr .= ' AS ' . $this->quote($alias);
        }

        return $this->select($expr);
    }

    #[\Override]
    public function percentileCont(float $fraction, string $orderColumn, string $alias = ''): static
    {
        $expr = 'PERCENTILE_CONT(?) WITHIN GROUP (ORDER BY ' . $this->resolveAndWrap($orderColumn) . ')';
        if ($alias !== '') {
            $expr .= ' AS ' . $this->quote($alias);
        }

        return $this->select($expr, [$fraction]);
    }

    #[\Override]
    public function percentileDisc(float $fraction, string $orderColumn, string $alias = ''): static
    {
        $expr = 'PERCENTILE_DISC(?) WITHIN GROUP (ORDER BY ' . $this->resolveAndWrap($orderColumn) . ')';
        if ($alias !== '') {
            $expr .= ' AS ' . $this->quote($alias);
        }

        return $this->select($expr, [$fraction]);
    }
}
