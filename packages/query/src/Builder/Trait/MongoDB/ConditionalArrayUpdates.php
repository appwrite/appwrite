<?php

namespace Utopia\Query\Builder\Trait\MongoDB;

trait ConditionalArrayUpdates
{
    /**
     * Register an arrayFilters document for a positional filtered update such
     * as `field.$[identifier]`. The identifier argument is informational and
     * must appear as the root-level path in $condition (e.g. identifier
     * 'elem' paired with `['elem.grade' => ['$gte' => 85]]`).
     *
     * @param  array<string, mixed>  $condition
     */
    #[\Override]
    public function arrayFilter(string $identifier, array $condition): static
    {
        $this->arrayFilters[] = $condition;

        return $this;
    }
}
