<?php

namespace Utopia\Query\Builder\Trait;

use Utopia\Query\Query;

trait Json
{
    public function filterJsonContains(string $attribute, mixed $value): static
    {
        $this->pendingQueries[] = Query::jsonContains($attribute, $value);

        return $this;
    }

    public function filterJsonNotContains(string $attribute, mixed $value): static
    {
        $this->pendingQueries[] = Query::jsonNotContains($attribute, $value);

        return $this;
    }

    /**
     * @param  array<mixed>  $values
     */
    public function filterJsonOverlaps(string $attribute, array $values): static
    {
        $this->pendingQueries[] = Query::jsonOverlaps($attribute, $values);

        return $this;
    }

    public function filterJsonPath(string $attribute, string $path, string $operator, mixed $value): static
    {
        $this->pendingQueries[] = Query::jsonPath($attribute, $path, $operator, $value);

        return $this;
    }
}
