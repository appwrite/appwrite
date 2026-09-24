<?php

namespace Utopia\Query\Builder\Trait\PostgreSQL;

trait DistinctOn
{
    /**
     * @param  list<string>  $columns
     */
    #[\Override]
    public function distinctOn(array $columns): static
    {
        $this->distinctOnColumns = $columns;

        return $this;
    }
}
