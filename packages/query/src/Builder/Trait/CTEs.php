<?php

namespace Utopia\Query\Builder\Trait;

use Utopia\Query\Builder;
use Utopia\Query\Builder\CteClause;

trait CTEs
{
    /**
     * @param  list<string>  $columns
     */
    #[\Override]
    public function with(string $name, Builder $query, array $columns = []): static
    {
        $result = $query->build();
        $this->ctes[] = new CteClause($name, $result->query, $result->bindings, false, $columns);

        return $this;
    }

    /**
     * @param  list<string>  $columns
     */
    #[\Override]
    public function withRecursive(string $name, Builder $query, array $columns = []): static
    {
        $result = $query->build();
        $this->ctes[] = new CteClause($name, $result->query, $result->bindings, true, $columns);

        return $this;
    }

    /**
     * @param  list<string>  $columns
     */
    #[\Override]
    public function withRecursiveSeedStep(string $name, Builder $seed, Builder $step, array $columns = []): static
    {
        $seedResult = $seed->build();
        $stepResult = $step->build();
        $query = $seedResult->query . ' UNION ALL ' . $stepResult->query;
        $bindings = \array_merge($seedResult->bindings, $stepResult->bindings);
        $this->ctes[] = new CteClause($name, $query, $bindings, true, $columns);

        return $this;
    }
}
