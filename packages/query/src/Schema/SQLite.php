<?php

namespace Utopia\Query\Schema;

use Utopia\Query\Builder\Statement;
use Utopia\Query\Exception\UnsupportedException;
use Utopia\Query\Schema\Feature\Views;

class SQLite extends SQL implements Views
{
    use Trait\Views;

    #[\Override]
    public function table(string $name): Table\SQLite
    {
        return new Table\SQLite($this, $name);
    }

    protected function compileColumnType(Column $column): string
    {
        return match ($column->type) {
            ColumnType::String, ColumnType::Varchar, ColumnType::Relationship => 'VARCHAR(' . ($column->length ?? 255) . ')',
            ColumnType::Text, ColumnType::MediumText, ColumnType::LongText => 'TEXT',
            ColumnType::TinyInteger, ColumnType::SmallInteger,
            ColumnType::Integer, ColumnType::BigInteger, ColumnType::Id,
            ColumnType::Serial, ColumnType::BigSerial, ColumnType::SmallSerial => 'INTEGER',
            ColumnType::Float, ColumnType::Double => 'REAL',
            ColumnType::Decimal => 'NUMERIC(' . ($column->precision ?? 10) . ', ' . ($column->scale ?? 0) . ')',
            ColumnType::Boolean => 'INTEGER',
            ColumnType::Datetime, ColumnType::Timestamp => 'TEXT',
            ColumnType::Json, ColumnType::Object => 'TEXT',
            ColumnType::Binary => 'BLOB',
            ColumnType::Enum => 'TEXT',
            ColumnType::Point, ColumnType::Linestring, ColumnType::Polygon => 'TEXT',
            ColumnType::Uuid => 'TEXT',
            ColumnType::Uuid7 => 'VARCHAR(36)',
            ColumnType::Vector => throw new UnsupportedException('Vector type is not supported in SQLite.'),
            ColumnType::Array, ColumnType::Tuple => throw new UnsupportedException('Array/Tuple column types are not supported in SQLite.'),
        };
    }

    protected function compileAutoIncrement(): string
    {
        return 'AUTOINCREMENT';
    }

    /**
     * SQLite requires `AUTOINCREMENT` to be paired with `INTEGER PRIMARY KEY`
     * inline on the same column declaration. Emit those keywords together and
     * skip the separate `PRIMARY KEY (col)` clause that the base class adds
     * at the end of the column list.
     */
    #[\Override]
    protected function compileColumnDefinition(Column $column): string
    {
        if (! $column->isAutoIncrement || ! $column->isPrimary) {
            return parent::compileColumnDefinition($column);
        }

        $parts = [
            $this->quoteLiteral($column->name),
            $this->compileColumnType($column),
            'PRIMARY KEY',
            $this->compileAutoIncrement(),
        ];

        if (! $column->isNullable) {
            $parts[] = 'NOT NULL';
        }

        if ($column->defaultRaw !== null) {
            $parts[] = 'DEFAULT ' . $column->defaultRaw;
        } elseif ($column->hasDefault) {
            $parts[] = 'DEFAULT ' . $this->compileDefaultValue($column->default);
        }

        if ($column->checkExpression !== null) {
            $parts[] = 'CHECK (' . $column->checkExpression . ')';
        }

        return \implode(' ', $parts);
    }

    /**
     * SQLite emits its primary key inline when paired with `AUTOINCREMENT`, so
     * suppress the redundant trailing `PRIMARY KEY (col)` constraint that the
     * base compiler would otherwise add.
     */
    #[\Override]
    public function compileCreate(\Utopia\Query\Schema\Table $table, bool $ifNotExists = false): Statement
    {
        $hasInlinePrimary = false;
        foreach ($table->columns as $column) {
            if ($column->isAutoIncrement && $column->isPrimary) {
                $hasInlinePrimary = true;
                break;
            }
        }

        if (! $hasInlinePrimary) {
            return parent::compileCreate($table, $ifNotExists);
        }

        $statement = parent::compileCreate($table, $ifNotExists);
        $sql = \preg_replace(
            '/, PRIMARY KEY \(`[^`]+`\)/',
            '',
            $statement->query,
            1,
        );

        return new Statement($sql ?? $statement->query, $statement->bindings, executor: $this->executor);
    }

    protected function compileUnsigned(): string
    {
        return '';
    }

    #[\Override]
    public function compileRename(string $from, string $to): Statement
    {
        return new Statement(
            'ALTER TABLE ' . $this->quote($from) . ' RENAME TO ' . $this->quote($to),
            [],
            executor: $this->executor,
        );
    }

    #[\Override]
    public function compileTruncate(string $name): Statement
    {
        return new Statement('DELETE FROM ' . $this->quote($name), [], executor: $this->executor);
    }

    public function dropIndex(string $table, string $name): Statement
    {
        return new Statement('DROP INDEX ' . $this->quote($name), [], executor: $this->executor);
    }
}
