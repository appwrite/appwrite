<?php

namespace Utopia\Query\Builder\Trait;

use Utopia\Query\Builder\Statement;
use Utopia\Query\Exception\ValidationException;

trait UpsertSelect
{
    public function upsertSelect(): Statement
    {
        $this->bindings = [];
        $this->validateTable();

        if ($this->insertSelectSource === null) {
            throw new ValidationException('No SELECT source specified. Call fromSelect() before upsertSelect().');
        }
        if (empty($this->insertSelectColumns)) {
            throw new ValidationException('No columns specified. Call fromSelect() with columns before upsertSelect().');
        }
        if (empty($this->conflictKeys)) {
            throw new ValidationException('No conflict keys specified. Call onConflict() before upsertSelect().');
        }
        if (empty($this->conflictUpdateColumns)) {
            throw new ValidationException('No conflict update columns specified. Call onConflict() with update columns before upsertSelect().');
        }

        $wrappedColumns = \array_map(
            fn (string $col): string => $this->resolveAndWrap($col),
            $this->insertSelectColumns
        );

        $sourceResult = $this->insertSelectSource->build();

        $sql = 'INSERT INTO ' . $this->quote($this->table)
            . ' (' . \implode(', ', $wrappedColumns) . ')'
            . ' ' . $sourceResult->query;

        $this->addBindings($sourceResult->bindings);

        $sql .= ' ' . $this->compileConflictClause();

        return new Statement($sql, $this->getBindingValues(), executor: $this->executor);
    }
}
