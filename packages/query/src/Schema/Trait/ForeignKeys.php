<?php

namespace Utopia\Query\Schema\Trait;

use Utopia\Query\Builder\Statement;
use Utopia\Query\Schema\ForeignKeyAction;

trait ForeignKeys
{
    public function addForeignKey(
        string $table,
        string $name,
        string $column,
        string $refTable,
        string $refColumn,
        ?ForeignKeyAction $onDelete = null,
        ?ForeignKeyAction $onUpdate = null,
    ): Statement {
        $sql = 'ALTER TABLE ' . $this->quote($table)
            . ' ADD CONSTRAINT ' . $this->quote($name)
            . ' FOREIGN KEY (' . $this->quoteLiteral($column) . ')'
            . ' REFERENCES ' . $this->quote($refTable)
            . ' (' . $this->quoteLiteral($refColumn) . ')';

        if ($onDelete !== null) {
            $sql .= ' ON DELETE ' . $onDelete->toSql();
        }
        if ($onUpdate !== null) {
            $sql .= ' ON UPDATE ' . $onUpdate->toSql();
        }

        return new Statement($sql, [], executor: $this->executor);
    }

    public function dropForeignKey(string $table, string $name): Statement
    {
        return new Statement(
            'ALTER TABLE ' . $this->quote($table)
            . ' DROP FOREIGN KEY ' . $this->quote($name),
            [],
            executor: $this->executor,
        );
    }
}
