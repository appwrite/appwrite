<?php

namespace Utopia\Query\Schema\Table\Trait;

use Utopia\Query\Exception\ValidationException;

trait CompositePrimary
{
    /**
     * Declare a composite PRIMARY KEY across two or more columns.
     *
     * For a single-column primary key, use {@see Column::primary()} instead.
     *
     * @param  list<string>  $columns
     *
     * @throws ValidationException if fewer than two columns are provided or any column name is invalid.
     */
    public function primary(array $columns): static
    {
        if (\count($columns) < 2) {
            throw new ValidationException('Table::primary(array) requires at least two columns; use Column::primary() for single-column keys.');
        }

        foreach ($columns as $column) {
            if (! \preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column)) {
                throw new ValidationException('Invalid column name in composite primary key: ' . $column);
            }
        }

        $this->compositePrimaryKey = $columns;

        return $this;
    }
}
