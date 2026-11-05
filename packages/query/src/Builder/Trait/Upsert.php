<?php

namespace Utopia\Query\Builder\Trait;

use Utopia\Query\Builder\Statement;
use Utopia\Query\Exception\ValidationException;

trait Upsert
{
    public function upsert(): Statement
    {
        $this->bindings = [];
        $this->validateTable();
        $this->validateRows('upsert');
        $columns = $this->validateAndGetColumns();

        if (empty($this->conflictKeys)) {
            throw new ValidationException('No conflict keys specified. Call onConflict() before upsert().');
        }

        if (empty($this->conflictUpdateColumns)) {
            throw new ValidationException('No conflict update columns specified. Call onConflict() with update columns before upsert().');
        }

        $rowColumns = $columns;
        foreach ($this->conflictUpdateColumns as $col) {
            if (! \in_array($col, $rowColumns, true)) {
                throw new ValidationException("Conflict update column '{$col}' is not present in the row data.");
            }
        }

        $wrappedColumns = \array_map(fn (string $col): string => $this->resolveAndWrap($col), $columns);

        $rowPlaceholders = [];
        foreach ($this->rows as $row) {
            $placeholders = [];
            foreach ($columns as $col) {
                $this->addBinding($row[$col] ?? null);
                if (isset($this->insertColumnExpressions[$col])) {
                    $placeholders[] = $this->insertColumnExpressions[$col];
                    foreach ($this->insertColumnExpressionBindings[$col] ?? [] as $extra) {
                        $this->addBinding($extra);
                    }
                } else {
                    $placeholders[] = '?';
                }
            }
            $rowPlaceholders[] = '(' . \implode(', ', $placeholders) . ')';
        }

        $tablePart = $this->quote($this->table);
        if ($this->insertAlias !== '') {
            $tablePart .= ' AS ' . $this->quote($this->insertAlias);
        }

        $sql = 'INSERT INTO ' . $tablePart
            . ' (' . \implode(', ', $wrappedColumns) . ')'
            . ' VALUES ' . \implode(', ', $rowPlaceholders);

        $sql .= ' ' . $this->compileConflictClause();

        return new Statement($sql, $this->getBindingValues(), executor: $this->executor);
    }
}
