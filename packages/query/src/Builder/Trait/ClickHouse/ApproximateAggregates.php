<?php

namespace Utopia\Query\Builder\Trait\ClickHouse;

use Utopia\Query\Exception\ValidationException;

trait ApproximateAggregates
{
    #[\Override]
    public function quantile(float $level, string $column, string $alias = ''): static
    {
        $expr = 'quantile(' . $level . ')(' . $this->resolveAndWrap($column) . ')';
        if ($alias !== '') {
            $expr .= ' AS ' . $this->quote($alias);
        }

        return $this->select($expr);
    }

    /**
     * @param  list<float>  $levels
     */
    #[\Override]
    public function quantiles(array $levels, string $column, string $alias = ''): static
    {
        if ($levels === []) {
            throw new ValidationException('quantiles() requires at least one level.');
        }

        foreach ($levels as $level) {
            if ($level < 0.0 || $level > 1.0) {
                throw new ValidationException('quantiles() levels must be in the range [0, 1].');
            }
        }

        $expr = 'quantiles(' . \implode(', ', $levels) . ')(' . $this->resolveAndWrap($column) . ')';
        if ($alias !== '') {
            $expr .= ' AS ' . $this->quote($alias);
        }

        return $this->select($expr);
    }

    #[\Override]
    public function quantileExact(float $level, string $column, string $alias = ''): static
    {
        $expr = 'quantileExact(' . $level . ')(' . $this->resolveAndWrap($column) . ')';
        if ($alias !== '') {
            $expr .= ' AS ' . $this->quote($alias);
        }

        return $this->select($expr);
    }

    #[\Override]
    public function median(string $column, string $alias = ''): static
    {
        $expr = 'median(' . $this->resolveAndWrap($column) . ')';
        if ($alias !== '') {
            $expr .= ' AS ' . $this->quote($alias);
        }

        return $this->select($expr);
    }

    #[\Override]
    public function uniq(string $column, string $alias = ''): static
    {
        $expr = 'uniq(' . $this->resolveAndWrap($column) . ')';
        if ($alias !== '') {
            $expr .= ' AS ' . $this->quote($alias);
        }

        return $this->select($expr);
    }

    #[\Override]
    public function uniqExact(string $column, string $alias = ''): static
    {
        $expr = 'uniqExact(' . $this->resolveAndWrap($column) . ')';
        if ($alias !== '') {
            $expr .= ' AS ' . $this->quote($alias);
        }

        return $this->select($expr);
    }

    #[\Override]
    public function uniqCombined(string $column, string $alias = ''): static
    {
        $expr = 'uniqCombined(' . $this->resolveAndWrap($column) . ')';
        if ($alias !== '') {
            $expr .= ' AS ' . $this->quote($alias);
        }

        return $this->select($expr);
    }

    #[\Override]
    public function argMin(string $valueColumn, string $argColumn, string $alias = ''): static
    {
        $expr = 'argMin(' . $this->resolveAndWrap($valueColumn) . ', ' . $this->resolveAndWrap($argColumn) . ')';
        if ($alias !== '') {
            $expr .= ' AS ' . $this->quote($alias);
        }

        return $this->select($expr);
    }

    #[\Override]
    public function argMax(string $valueColumn, string $argColumn, string $alias = ''): static
    {
        $expr = 'argMax(' . $this->resolveAndWrap($valueColumn) . ', ' . $this->resolveAndWrap($argColumn) . ')';
        if ($alias !== '') {
            $expr .= ' AS ' . $this->quote($alias);
        }

        return $this->select($expr);
    }

    #[\Override]
    public function topK(int $k, string $column, string $alias = ''): static
    {
        $expr = 'topK(' . $k . ')(' . $this->resolveAndWrap($column) . ')';
        if ($alias !== '') {
            $expr .= ' AS ' . $this->quote($alias);
        }

        return $this->select($expr);
    }

    #[\Override]
    public function topKWeighted(int $k, string $column, string $weightColumn, string $alias = ''): static
    {
        $expr = 'topKWeighted(' . $k . ')(' . $this->resolveAndWrap($column) . ', ' . $this->resolveAndWrap($weightColumn) . ')';
        if ($alias !== '') {
            $expr .= ' AS ' . $this->quote($alias);
        }

        return $this->select($expr);
    }

    #[\Override]
    public function anyValue(string $column, string $alias = ''): static
    {
        $expr = 'any(' . $this->resolveAndWrap($column) . ')';
        if ($alias !== '') {
            $expr .= ' AS ' . $this->quote($alias);
        }

        return $this->select($expr);
    }

    #[\Override]
    public function anyLastValue(string $column, string $alias = ''): static
    {
        $expr = 'anyLast(' . $this->resolveAndWrap($column) . ')';
        if ($alias !== '') {
            $expr .= ' AS ' . $this->quote($alias);
        }

        return $this->select($expr);
    }

    #[\Override]
    public function groupUniqArray(string $column, string $alias = ''): static
    {
        $expr = 'groupUniqArray(' . $this->resolveAndWrap($column) . ')';
        if ($alias !== '') {
            $expr .= ' AS ' . $this->quote($alias);
        }

        return $this->select($expr);
    }

    #[\Override]
    public function groupArrayMovingAvg(string $column, string $alias = ''): static
    {
        $expr = 'groupArrayMovingAvg(' . $this->resolveAndWrap($column) . ')';
        if ($alias !== '') {
            $expr .= ' AS ' . $this->quote($alias);
        }

        return $this->select($expr);
    }

    #[\Override]
    public function groupArrayMovingSum(string $column, string $alias = ''): static
    {
        $expr = 'groupArrayMovingSum(' . $this->resolveAndWrap($column) . ')';
        if ($alias !== '') {
            $expr .= ' AS ' . $this->quote($alias);
        }

        return $this->select($expr);
    }
}
