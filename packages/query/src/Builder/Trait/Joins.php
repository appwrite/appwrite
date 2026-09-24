<?php

namespace Utopia\Query\Builder\Trait;

use Closure;
use Utopia\Query\Builder\JoinBuilder;
use Utopia\Query\Builder\JoinType;
use Utopia\Query\Method;
use Utopia\Query\Query;

trait Joins
{
    #[\Override]
    public function join(string $table, string $left, string $right, string $operator = '=', string $alias = ''): static
    {
        $this->pendingQueries[] = Query::join($table, $left, $right, $operator, $alias);

        return $this;
    }

    #[\Override]
    public function leftJoin(string $table, string $left, string $right, string $operator = '=', string $alias = ''): static
    {
        $this->pendingQueries[] = Query::leftJoin($table, $left, $right, $operator, $alias);

        return $this;
    }

    #[\Override]
    public function rightJoin(string $table, string $left, string $right, string $operator = '=', string $alias = ''): static
    {
        $this->pendingQueries[] = Query::rightJoin($table, $left, $right, $operator, $alias);

        return $this;
    }

    /**
     * @param  \Closure(JoinBuilder): void  $callback
     */
    #[\Override]
    public function joinWhere(string $table, Closure $callback, JoinType $type = JoinType::Inner, string $alias = ''): static
    {
        $joinBuilder = new JoinBuilder();
        $callback($joinBuilder);

        $method = match ($type) {
            JoinType::Left => Method::LeftJoin,
            JoinType::Right => Method::RightJoin,
            JoinType::Cross => Method::CrossJoin,
            JoinType::FullOuter => Method::FullOuterJoin,
            JoinType::Natural => Method::NaturalJoin,
            default => Method::Join,
        };

        if ($method === Method::CrossJoin || $method === Method::NaturalJoin) {
            $this->pendingQueries[] = new Query($method, $table, $alias !== '' ? [$alias] : []);
        } else {
            // Use placeholder values; the JoinBuilder will handle the ON clause
            $values = ['', '=', ''];
            if ($alias !== '') {
                $values[] = $alias;
            }
            $this->pendingQueries[] = new Query($method, $table, $values);
        }

        $index = \count($this->pendingQueries) - 1;
        $this->joins[$index] = $joinBuilder;

        return $this;
    }
}
