<?php

namespace Utopia\Query\Builder\Feature;

use Utopia\Query\Builder;

interface Unions
{
    public function union(Builder $other): static;

    public function unionAll(Builder $other): static;

    public function intersect(Builder $other): static;

    public function intersectAll(Builder $other): static;

    public function except(Builder $other): static;

    public function exceptAll(Builder $other): static;
}
