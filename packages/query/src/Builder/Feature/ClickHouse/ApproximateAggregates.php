<?php

namespace Utopia\Query\Builder\Feature\ClickHouse;

interface ApproximateAggregates
{
    public function quantile(float $level, string $column, string $alias = ''): static;

    /**
     * Emit `quantiles(level1, level2, ...)(column)` — multiple approximate quantiles in one pass.
     *
     * Adds to SELECT. Column is quoted per dialect. Levels must be floats in [0, 1].
     *
     * @param  list<float>  $levels  Quantile levels in [0, 1]. Must be non-empty.
     */
    public function quantiles(array $levels, string $column, string $alias = ''): static;

    public function quantileExact(float $level, string $column, string $alias = ''): static;

    public function median(string $column, string $alias = ''): static;

    public function uniq(string $column, string $alias = ''): static;

    public function uniqExact(string $column, string $alias = ''): static;

    public function uniqCombined(string $column, string $alias = ''): static;

    public function argMin(string $valueColumn, string $argColumn, string $alias = ''): static;

    public function argMax(string $valueColumn, string $argColumn, string $alias = ''): static;

    public function topK(int $k, string $column, string $alias = ''): static;

    public function topKWeighted(int $k, string $column, string $weightColumn, string $alias = ''): static;

    public function anyValue(string $column, string $alias = ''): static;

    public function anyLastValue(string $column, string $alias = ''): static;

    public function groupUniqArray(string $column, string $alias = ''): static;

    public function groupArrayMovingAvg(string $column, string $alias = ''): static;

    public function groupArrayMovingSum(string $column, string $alias = ''): static;
}
