<?php

namespace Utopia\Query\Builder\Trait;

use Utopia\Query\Query;

trait StatisticalAggregates
{
    #[\Override]
    public function stddev(string $attribute, string $alias = ''): static
    {
        $this->pendingQueries[] = Query::stddev($attribute, $alias);

        return $this;
    }

    #[\Override]
    public function stddevPop(string $attribute, string $alias = ''): static
    {
        $this->pendingQueries[] = Query::stddevPop($attribute, $alias);

        return $this;
    }

    #[\Override]
    public function stddevSamp(string $attribute, string $alias = ''): static
    {
        $this->pendingQueries[] = Query::stddevSamp($attribute, $alias);

        return $this;
    }

    #[\Override]
    public function variance(string $attribute, string $alias = ''): static
    {
        $this->pendingQueries[] = Query::variance($attribute, $alias);

        return $this;
    }

    #[\Override]
    public function varPop(string $attribute, string $alias = ''): static
    {
        $this->pendingQueries[] = Query::varPop($attribute, $alias);

        return $this;
    }

    #[\Override]
    public function varSamp(string $attribute, string $alias = ''): static
    {
        $this->pendingQueries[] = Query::varSamp($attribute, $alias);

        return $this;
    }
}
