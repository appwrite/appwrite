<?php

namespace Utopia\Query\Builder\Trait;

trait GroupByModifiers
{
    protected ?string $groupByModifier = null;

    protected function resetGroupByModifier(): void
    {
        $this->groupByModifier = null;
    }
}
