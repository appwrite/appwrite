<?php

namespace Utopia\Query\Builder\Trait;

use Utopia\Query\Builder;
use Utopia\Query\Builder\UnionClause;
use Utopia\Query\Builder\UnionType;

trait Unions
{
    #[\Override]
    public function union(Builder $other): static
    {
        $result = $other->build();
        $this->unions[] = new UnionClause(UnionType::Union, $result->query, $result->bindings);

        return $this;
    }

    #[\Override]
    public function unionAll(Builder $other): static
    {
        $result = $other->build();
        $this->unions[] = new UnionClause(UnionType::UnionAll, $result->query, $result->bindings);

        return $this;
    }

    #[\Override]
    public function intersect(Builder $other): static
    {
        $result = $other->build();
        $this->unions[] = new UnionClause(UnionType::Intersect, $result->query, $result->bindings);

        return $this;
    }

    #[\Override]
    public function intersectAll(Builder $other): static
    {
        $result = $other->build();
        $this->unions[] = new UnionClause(UnionType::IntersectAll, $result->query, $result->bindings);

        return $this;
    }

    #[\Override]
    public function except(Builder $other): static
    {
        $result = $other->build();
        $this->unions[] = new UnionClause(UnionType::Except, $result->query, $result->bindings);

        return $this;
    }

    #[\Override]
    public function exceptAll(Builder $other): static
    {
        $result = $other->build();
        $this->unions[] = new UnionClause(UnionType::ExceptAll, $result->query, $result->bindings);

        return $this;
    }
}
