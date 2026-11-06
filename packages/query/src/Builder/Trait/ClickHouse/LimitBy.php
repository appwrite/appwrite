<?php

namespace Utopia\Query\Builder\Trait\ClickHouse;

trait LimitBy
{
    /**
     * @param  list<string>  $columns
     */
    #[\Override]
    public function limitBy(int $count, array $columns): static
    {
        $this->limitByClause = ['count' => $count, 'columns' => $columns];

        return $this;
    }
}
