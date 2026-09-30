<?php

namespace Utopia\Query\Builder\Feature;

interface Aggregates
{
    public function count(string $attribute = '*', string $alias = ''): static;

    public function countDistinct(string $attribute, string $alias = ''): static;

    public function sum(string $attribute, string $alias = ''): static;

    public function avg(string $attribute, string $alias = ''): static;

    public function min(string $attribute, string $alias = ''): static;

    public function max(string $attribute, string $alias = ''): static;

    /**
     * @param  array<string>  $columns
     */
    public function groupBy(array $columns): static;

    /**
     * Group rows by a time bucket of `$attribute` (e.g. hourly, daily).
     *
     * Allowed intervals are listed in
     * `\Utopia\Query\Query::GROUP_BY_TIME_BUCKET_INTERVALS`. Compilation is
     * dialect-specific — only dialects that support time bucketing accept
     * this call; others throw `UnsupportedException` at build time.
     */
    public function groupByTimeBucket(string $attribute, string $interval): static;

    /**
     * @param  array<\Utopia\Query\Query>  $queries
     */
    public function having(array $queries): static;
}
