<?php

namespace Utopia\Query\Builder\Trait\ClickHouse;

trait ArrayJoins
{
    #[\Override]
    public function arrayJoin(string $column, string $alias = ''): static
    {
        $this->arrayJoins[] = ['type' => 'ARRAY JOIN', 'column' => $column, 'alias' => $alias];

        return $this;
    }

    #[\Override]
    public function leftArrayJoin(string $column, string $alias = ''): static
    {
        $this->arrayJoins[] = ['type' => 'LEFT ARRAY JOIN', 'column' => $column, 'alias' => $alias];

        return $this;
    }
}
