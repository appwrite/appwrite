<?php

namespace Utopia\Query\Builder\Trait\ClickHouse;

use Utopia\Query\Builder\Condition;
use Utopia\Query\Exception\ValidationException;

trait WithFill
{
    #[\Override]
    public function orderWithFill(string $column, string $direction = 'ASC', mixed $from = null, mixed $to = null, mixed $step = null): static
    {
        $normalized = \strtoupper($direction);
        if ($normalized !== 'ASC' && $normalized !== 'DESC') {
            throw new ValidationException('Invalid direction for orderWithFill: ' . $direction);
        }

        $expr = $this->resolveAndWrap($column) . ' ' . $normalized . ' WITH FILL';
        $bindings = [];

        if ($from !== null) {
            $expr .= ' FROM ?';
            $bindings[] = $from;
        }
        if ($to !== null) {
            $expr .= ' TO ?';
            $bindings[] = $to;
        }
        if ($step !== null) {
            $expr .= ' STEP ?';
            $bindings[] = $step;
        }

        $this->rawOrders[] = new Condition($expr, $bindings);

        return $this;
    }
}
