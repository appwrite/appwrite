<?php

namespace Utopia\Query\Builder\Trait;

use Utopia\Query\Query;

trait Aggregates
{
    #[\Override]
    public function count(string $attribute = '*', string $alias = ''): static
    {
        $this->pendingQueries[] = Query::count($attribute, $alias);

        return $this;
    }

    #[\Override]
    public function countDistinct(string $attribute, string $alias = ''): static
    {
        $this->pendingQueries[] = Query::countDistinct($attribute, $alias);

        return $this;
    }

    #[\Override]
    public function sum(string $attribute, string $alias = ''): static
    {
        $this->pendingQueries[] = Query::sum($attribute, $alias);

        return $this;
    }

    #[\Override]
    public function avg(string $attribute, string $alias = ''): static
    {
        $this->pendingQueries[] = Query::avg($attribute, $alias);

        return $this;
    }

    #[\Override]
    public function min(string $attribute, string $alias = ''): static
    {
        $this->pendingQueries[] = Query::min($attribute, $alias);

        return $this;
    }

    #[\Override]
    public function max(string $attribute, string $alias = ''): static
    {
        $this->pendingQueries[] = Query::max($attribute, $alias);

        return $this;
    }

    /**
     * @param  array<string>  $columns
     */
    #[\Override]
    public function groupBy(array $columns): static
    {
        $this->pendingQueries[] = Query::groupBy($columns);

        return $this;
    }

    #[\Override]
    public function groupByTimeBucket(string $attribute, string $interval): static
    {
        $this->pendingQueries[] = Query::groupByTimeBucket($attribute, $interval);

        return $this;
    }

    /**
     * @param  array<Query>  $queries
     */
    #[\Override]
    public function having(array $queries): static
    {
        $this->pendingQueries[] = Query::having($queries);

        return $this;
    }
}
