<?php

namespace Utopia\Query\Builder\Trait;

use Utopia\Query\Query;

trait FullTextSearch
{
    #[\Override]
    public function filterSearch(string $attribute, string $value): static
    {
        $this->pendingQueries[] = Query::search($attribute, $value);

        return $this;
    }
}
