<?php

namespace Utopia\Query;

use Closure;
use Utopia\Query\Builder\Statement;
use Utopia\Query\Exception\ValidationException;
use Utopia\Query\Schema\Column;
use Utopia\Query\Schema\IndexType;
use Utopia\Query\Schema\Order;
use Utopia\Query\Schema\Table;

abstract class Schema
{
    /** @var (Closure(Statement): (array<mixed>|int))|null */
    protected ?Closure $executor = null;

    /**
     * @param  Closure(Statement): (array<mixed>|int)  $executor
     */
    public function setExecutor(Closure $executor): static
    {
        $this->executor = $executor;

        return $this;
    }

    abstract protected function quote(string $identifier): string;

    abstract protected function quoteLiteral(string $identifier): string;

    abstract protected function compileColumnType(Column $column): string;

    abstract protected function compileAutoIncrement(): string;

    /**
     * Begin a fluent table builder. Terminal methods on the returned {@see Table}
     * (`create()`, `alter()`, `drop()`, `dropIfExists()`, `truncate()`, `rename()`)
     * compile and return the final {@see Statement}.
     *
     * Each dialect overrides this to return the dialect-specific {@see Table}
     * subclass exposing only methods supported by that dialect.
     */
    abstract public function table(string $name): Table;

    public function compileCreate(Table $table, bool $ifNotExists = false): Statement
    {
        $columnDefs = [];
        $primaryKeys = [];
        $uniqueColumns = [];

        foreach ($table->columns as $column) {
            $def = $this->compileColumnDefinition($column);
            $columnDefs[] = $def;

            if ($column->isPrimary) {
                $primaryKeys[] = $this->quoteLiteral($column->name);
            }
            if ($column->isUnique) {
                $uniqueColumns[] = $column->name;
            }
        }

        if (! empty($table->compositePrimaryKey) && ! empty($primaryKeys)) {
            throw new ValidationException('Cannot combine column-level primary() with Table::primary() composite key.');
        }

        // Raw column definitions (bypass typed Column objects)
        foreach ($table->rawColumnDefs as $rawDef) {
            $columnDefs[] = $rawDef;
        }

        // Inline PRIMARY KEY constraint
        if (! empty($primaryKeys)) {
            $columnDefs[] = 'PRIMARY KEY (' . \implode(', ', $primaryKeys) . ')';
        } elseif (! empty($table->compositePrimaryKey)) {
            $columnDefs[] = 'PRIMARY KEY ('
                . \implode(', ', \array_map(fn (string $c): string => $this->quoteLiteral($c), $table->compositePrimaryKey))
                . ')';
        }

        // Inline UNIQUE constraints for columns marked unique
        foreach ($uniqueColumns as $col) {
            $columnDefs[] = 'UNIQUE (' . $this->quoteLiteral($col) . ')';
        }

        // Table-level CHECK constraints
        foreach ($table->checks as $check) {
            $columnDefs[] = 'CONSTRAINT ' . $this->quote($check->name) . ' CHECK (' . $check->expression . ')';
        }

        // Indexes
        foreach ($table->indexes as $index) {
            $keyword = match ($index->type) {
                IndexType::Unique => 'UNIQUE INDEX',
                IndexType::Fulltext => 'FULLTEXT INDEX',
                IndexType::Spatial => 'SPATIAL INDEX',
                default => 'INDEX',
            };
            $columnDefs[] = $keyword . ' ' . $this->quote($index->name)
                . ' (' . $this->compileIndexColumns($index) . ')';
        }

        // Raw index definitions (bypass typed Index objects)
        foreach ($table->rawIndexDefs as $rawIdx) {
            $columnDefs[] = $rawIdx;
        }

        // Foreign keys
        foreach ($table->foreignKeys as $fk) {
            $def = 'FOREIGN KEY (' . $this->quoteLiteral($fk->column) . ')'
                . ' REFERENCES ' . $this->quote($fk->refTable)
                . ' (' . $this->quoteLiteral($fk->refColumn) . ')';
            if ($fk->onDelete !== null) {
                $def .= ' ON DELETE ' . $fk->onDelete->toSql();
            }
            if ($fk->onUpdate !== null) {
                $def .= ' ON UPDATE ' . $fk->onUpdate->toSql();
            }
            $columnDefs[] = $def;
        }

        $sql = 'CREATE TABLE ' . ($ifNotExists ? 'IF NOT EXISTS ' : '') . $this->quote($table->name)
            . ' (' . \implode(', ', $columnDefs) . ')';

        $suffix = $this->compileCreateSuffix($table);
        if ($suffix !== '') {
            $sql .= ' ' . $suffix;
        }

        return new Statement($sql, [], executor: $this->executor);
    }

    public function compileAlter(Table $table): Statement
    {
        $alterations = [];

        foreach ($table->columns as $column) {
            $keyword = $column->isModify ? 'MODIFY COLUMN' : 'ADD COLUMN';
            $def = $keyword . ' ' . $this->compileColumnDefinition($column);
            if ($column->after !== null) {
                $def .= ' AFTER ' . $this->quoteLiteral($column->after);
            }
            $alterations[] = $def;
        }

        foreach ($table->renameColumns as $rename) {
            $alterations[] = 'RENAME COLUMN ' . $this->quoteLiteral($rename->from)
                . ' TO ' . $this->quoteLiteral($rename->to);
        }

        foreach ($table->dropColumns as $col) {
            $alterations[] = 'DROP COLUMN ' . $this->quoteLiteral($col);
        }

        foreach ($table->indexes as $index) {
            $keyword = match ($index->type) {
                IndexType::Unique => 'ADD UNIQUE INDEX',
                IndexType::Fulltext => 'ADD FULLTEXT INDEX',
                IndexType::Spatial => 'ADD SPATIAL INDEX',
                default => 'ADD INDEX',
            };
            $alterations[] = $keyword . ' ' . $this->quote($index->name)
                . ' (' . $this->compileIndexColumns($index) . ')';
        }

        foreach ($table->dropIndexes as $name) {
            $alterations[] = 'DROP INDEX ' . $this->quote($name);
        }

        foreach ($table->foreignKeys as $fk) {
            $def = 'ADD FOREIGN KEY (' . $this->quoteLiteral($fk->column) . ')'
                . ' REFERENCES ' . $this->quote($fk->refTable)
                . ' (' . $this->quoteLiteral($fk->refColumn) . ')';
            if ($fk->onDelete !== null) {
                $def .= ' ON DELETE ' . $fk->onDelete->toSql();
            }
            if ($fk->onUpdate !== null) {
                $def .= ' ON UPDATE ' . $fk->onUpdate->toSql();
            }
            $alterations[] = $def;
        }

        foreach ($table->dropForeignKeys as $name) {
            $alterations[] = 'DROP FOREIGN KEY ' . $this->quote($name);
        }

        $sql = 'ALTER TABLE ' . $this->quote($table->name)
            . ' ' . \implode(', ', $alterations);

        return new Statement($sql, [], executor: $this->executor);
    }

    public function compileDrop(string $name, bool $ifExists): Statement
    {
        return new Statement(
            'DROP TABLE ' . ($ifExists ? 'IF EXISTS ' : '') . $this->quote($name),
            [],
            executor: $this->executor,
        );
    }

    public function compileRename(string $from, string $to): Statement
    {
        return new Statement(
            'RENAME TABLE ' . $this->quote($from) . ' TO ' . $this->quote($to),
            [],
            executor: $this->executor,
        );
    }

    public function compileTruncate(string $name): Statement
    {
        return new Statement('TRUNCATE TABLE ' . $this->quote($name), [], executor: $this->executor);
    }

    /**
     * @param  string[]  $columns
     * @param  array<string, int>  $lengths
     * @param  array<string, string>  $orders
     * @param  array<string, string>  $collations
     * @param  list<string>  $rawColumns  Raw SQL expressions appended to column list (bypass quoting)
     */
    public function createIndex(
        string $table,
        string $name,
        array $columns,
        bool $unique = false,
        string $type = '',
        string $method = '',
        string $operatorClass = '',
        array $lengths = [],
        array $orders = [],
        array $collations = [],
        array $rawColumns = [],
    ): Statement {
        $keyword = match (true) {
            $unique => 'CREATE UNIQUE INDEX',
            $type === 'fulltext' => 'CREATE FULLTEXT INDEX',
            $type === 'spatial' => 'CREATE SPATIAL INDEX',
            default => 'CREATE INDEX',
        };

        $indexType = $unique ? IndexType::Unique : ($type !== '' ? IndexType::from($type) : IndexType::Index);
        $index = new Schema\Index($name, $columns, $indexType, $lengths, $orders, $method, $operatorClass, $collations, $rawColumns);

        $sql = $keyword . ' ' . $this->quote($name)
            . ' ON ' . $this->quote($table);

        if ($method !== '') {
            $sql .= ' USING ' . \strtoupper($method);
        }

        $sql .= ' (' . $this->compileIndexColumns($index) . ')';

        return new Statement($sql, [], executor: $this->executor);
    }

    public function dropIndex(string $table, string $name): Statement
    {
        return new Statement(
            'DROP INDEX ' . $this->quote($name) . ' ON ' . $this->quote($table),
            [],
            executor: $this->executor,
        );
    }

    protected function compileColumnDefinition(Column $column): string
    {
        $parts = [
            $this->quoteLiteral($column->name),
            $this->compileColumnType($column),
        ];

        if ($column->collation !== null) {
            $parts[] = 'COLLATE ' . $this->quoteCollation($column->collation);
        }

        if ($column->isUnsigned) {
            $unsigned = $this->compileUnsigned();
            if ($unsigned !== '') {
                $parts[] = $unsigned;
            }
        }

        if ($column->generatedExpression !== null) {
            $parts[] = $this->compileGeneratedClause($column);

            if (! $column->isNullable) {
                $parts[] = 'NOT NULL';
            } else {
                $parts[] = 'NULL';
            }

            if ($column->checkExpression !== null) {
                $parts[] = 'CHECK (' . $column->checkExpression . ')';
            }

            if ($column->comment !== null) {
                $parts[] = "COMMENT '" . \str_replace(['\\', "'"], ['\\\\', "''"], $column->comment) . "'";
            }

            return \implode(' ', $parts);
        }

        if ($column->isAutoIncrement) {
            $parts[] = $this->compileAutoIncrement();
        }

        if (! $column->isNullable) {
            $parts[] = 'NOT NULL';
        } else {
            $parts[] = 'NULL';
        }

        if ($column->defaultRaw !== null) {
            $parts[] = 'DEFAULT ' . $column->defaultRaw;
        } elseif ($column->hasDefault) {
            $parts[] = 'DEFAULT ' . $this->compileDefaultValue($column->default);
        }

        if ($column->checkExpression !== null) {
            $parts[] = 'CHECK (' . $column->checkExpression . ')';
        }

        if ($column->comment !== null) {
            $parts[] = "COMMENT '" . \str_replace(['\\', "'"], ['\\\\', "''"], $column->comment) . "'";
        }

        return \implode(' ', $parts);
    }

    /**
     * Compile the `GENERATED ALWAYS AS (...) [STORED|VIRTUAL]` clause.
     *
     * Default storage is VIRTUAL when unspecified, per SQL standard.
     */
    protected function compileGeneratedClause(Column $column): string
    {
        $clause = 'GENERATED ALWAYS AS (' . $column->generatedExpression . ')';
        $stored = $column->generatedStored ?? false;

        return $clause . ' ' . ($stored ? 'STORED' : 'VIRTUAL');
    }

    protected function compileDefaultValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (\is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }

        /** @var string|int|float $value */
        return "'" . \str_replace(['\\', "'"], ['\\\\', "''"], (string) $value) . "'";
    }

    /**
     * Dialect-specific clauses appended after the closing paren of CREATE TABLE.
     *
     * Overridden by capability traits (e.g. Trait\Partitioning) rather than
     * gated on the schema's own type, so the base class does not need to know
     * which features exist.
     */
    protected function compileCreateSuffix(Table $table): string
    {
        return '';
    }

    /**
     * Render a column COLLATE name. MySQL and SQLite take a bare identifier;
     * PostgreSQL requires a quoted one.
     */
    protected function quoteCollation(string $collation): string
    {
        return $collation;
    }

    protected function compileUnsigned(): string
    {
        return 'UNSIGNED';
    }

    /**
     * Compile index column list with lengths, orders, collations, and operator classes.
     *
     * @throws ValidationException if a collation or order value is not safe to emit inline.
     */
    protected function compileIndexColumns(Schema\Index $index): string
    {
        $parts = [];

        foreach ($index->columns as $col) {
            $part = $this->quoteLiteral($col);

            if (isset($index->collations[$col])) {
                $collation = $index->collations[$col];
                if (! \preg_match('/^[A-Za-z0-9_]+$/', $collation)) {
                    throw new ValidationException('Invalid collation: ' . $collation);
                }
                $part .= ' COLLATE ' . $collation;
            }

            if (isset($index->lengths[$col])) {
                $part .= '(' . $index->lengths[$col] . ')';
            }

            if ($index->operatorClass !== '') {
                $part .= ' ' . $index->operatorClass;
            }

            if (isset($index->orders[$col])) {
                $order = \strtoupper($index->orders[$col]);
                if (Order::tryFrom($order) === null) {
                    throw new ValidationException('Invalid index order: ' . $index->orders[$col]);
                }
                $part .= ' ' . $order;
            }

            $parts[] = $part;
        }

        // Append raw expressions (bypass quoting) — for CAST ARRAY, JSONB paths, etc.
        foreach ($index->rawColumns as $raw) {
            $parts[] = $raw;
        }

        return \implode(', ', $parts);
    }

}
