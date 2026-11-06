<?php

namespace Utopia\Query\Builder\Trait;

use Utopia\Query\Query;

trait NegatedFullTextSearch
{
    #[\Override]
    public function filterNotSearch(string $attribute, string $value): static
    {
        $this->pendingQueries[] = Query::notSearch($attribute, $value);

        return $this;
    }
}
