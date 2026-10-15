<?php

namespace Utopia\Query\Builder\Trait;

use Utopia\Query\Builder\Statement;

trait Returning
{
    /** @var list<string> */
    protected array $returningColumns = [];

    /**
     * @param  list<string>  $columns
     */
    #[\Override]
    public function returning(array $columns = ['*']): static
    {
        $this->returningColumns = $columns;

        return $this;
    }

    protected function appendReturning(Statement $result): Statement
    {
        if (empty($this->returningColumns)) {
            return $result;
        }

        $columns = \array_map(
            fn (string $col): string => $col === '*' ? '*' : $this->resolveAndWrap($col),
            $this->returningColumns
        );

        return new Statement(
            $result->query . ' RETURNING ' . \implode(', ', $columns),
            $result->bindings,
            executor: $this->executor,
        );
    }

    protected function resetReturning(): void
    {
        $this->returningColumns = [];
    }
}
