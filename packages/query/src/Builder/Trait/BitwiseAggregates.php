<?php

namespace Utopia\Query\Builder\Trait;

use Utopia\Query\Query;

trait BitwiseAggregates
{
    #[\Override]
    public function bitAnd(string $attribute, string $alias = ''): static
    {
        $this->pendingQueries[] = Query::bitAnd($attribute, $alias);

        return $this;
    }

    #[\Override]
    public function bitOr(string $attribute, string $alias = ''): static
    {
        $this->pendingQueries[] = Query::bitOr($attribute, $alias);

        return $this;
    }

    #[\Override]
    public function bitXor(string $attribute, string $alias = ''): static
    {
        $this->pendingQueries[] = Query::bitXor($attribute, $alias);

        return $this;
    }
}
