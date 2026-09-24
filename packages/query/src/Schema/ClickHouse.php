<?php

namespace Utopia\Query\Schema;

use Utopia\Query\Builder\Statement;
use Utopia\Query\Exception\UnsupportedException;
use Utopia\Query\Exception\ValidationException;
use Utopia\Query\QuotesIdentifiers;
use Utopia\Query\Schema;
use Utopia\Query\Schema\ClickHouse\Engine;
use Utopia\Query\Schema\Feature\ColumnComments;
use Utopia\Query\Schema\Feature\Databases;
use Utopia\Query\Schema\Feature\DropPartition;
use Utopia\Query\Schema\Feature\MaterializedViews;
use Utopia\Query\Schema\Feature\TableComments;
use Utopia\Query\Schema\Feature\Views;

class ClickHouse extends Schema implements TableComments, ColumnComments, DropPartition, Views, Databases, MaterializedViews
{
    use QuotesIdentifiers;
    use Trait\Databases;
    use Trait\MaterializedViews;
    use Trait\Views;

    #[\Override]
    public function table(string $name): Table\ClickHouse
    {
        return new Table\ClickHouse($this, $name);
    }

    protected function compileColumnType(Column $column): string
    {
        if ($column instanceof Column\ClickHouse && $column->isFixedString()) {
            $type = 'FixedString(' . $column->fixedStringLength . ')';

            if ($column->isNullable) {
                $type = 'Nullable(' . $type . ')';
            }

            if ($column->isLowCardinality) {
                $type = 'LowCardinality(' . $type . ')';
            }

            return $type;
        }

        if ($column instanceof Column\ClickHouse && $column->arrayElementType !== null) {
            if ($column->isLowCardinality) {
                throw new UnsupportedException('LowCardinality is not supported inside Array(...). Wrap the element type instead.');
            }

            if ($column->isNullable) {
                throw new UnsupportedException('Nullable(Array(...)) is not supported in ClickHouse. Use an empty array [] to represent a missing value instead.');
            }

            $inner = $this->compileNestedElementType($column->arrayElementType, $column);
            $type = 'Array(' . $inner . ')';

            return $type;
        }

        if ($column instanceof Column\ClickHouse && $column->tupleElementTypes !== []) {
            if ($column->isLowCardinality) {
                throw new UnsupportedException('LowCardinality is not supported on Tuple(...) columns.');
            }

            $inner = \implode(
                ', ',
                \array_map(
                    fn (ColumnType $element): string => $this->compileNestedElementType($element, $column),
                    $column->tupleElementTypes,
                ),
            );
            $type = 'Tuple(' . $inner . ')';

            if ($column->isNullable) {
                throw new UnsupportedException('Nullable(Tuple(...)) is an experimental ClickHouse feature and requires allow_experimental_nullable_tuple_type = 1. Use Tuple(Nullable(T1), Nullable(T2), ...) instead.');
            }

            return $type;
        }

        $type = match ($column->type) {
            ColumnType::String, ColumnType::Varchar, ColumnType::Relationship => 'String',
            ColumnType::Text => 'String',
            ColumnType::MediumText, ColumnType::LongText => 'String',
            ColumnType::TinyInteger => $column->isUnsigned ? 'UInt8' : 'Int8',
            ColumnType::SmallInteger => $column->isUnsigned ? 'UInt16' : 'Int16',
            ColumnType::Integer => $column->isUnsigned ? 'UInt32' : 'Int32',
            ColumnType::BigInteger, ColumnType::Id => $column->isUnsigned ? 'UInt64' : 'Int64',
            ColumnType::Serial, ColumnType::BigSerial, ColumnType::SmallSerial => throw new UnsupportedException('SERIAL types are not supported in ClickHouse. Reached via addColumn(); Table\\ClickHouse has no serial() factory.'),
            ColumnType::Float, ColumnType::Double => 'Float64',
            ColumnType::Decimal => 'Decimal(' . ($column->precision ?? 10) . ', ' . ($column->scale ?? 0) . ')',
            ColumnType::Boolean => 'UInt8',
            ColumnType::Datetime => $column->precision ? 'DateTime64(' . $column->precision . ')' : 'DateTime',
            ColumnType::Timestamp => $column->precision ? 'DateTime64(' . $column->precision . ')' : 'DateTime',
            ColumnType::Json, ColumnType::Object => 'String',
            ColumnType::Binary => 'String',
            ColumnType::Enum => $this->compileClickHouseEnum($column->enumValues),
            ColumnType::Point => 'Tuple(Float64, Float64)',
            ColumnType::Linestring => 'Array(Tuple(Float64, Float64))',
            ColumnType::Polygon => 'Array(Array(Tuple(Float64, Float64)))',
            ColumnType::Uuid => 'UUID',
            ColumnType::Uuid7 => 'FixedString(36)',
            ColumnType::Vector => 'Array(Float64)',
            ColumnType::Array, ColumnType::Tuple => throw new UnsupportedException(
                'Array/Tuple columns must be declared via Table\\ClickHouse::array() or ::tuple().'
            ),
        };

        if ($column->isNullable) {
            $type = 'Nullable(' . $type . ')';
        }

        if ($column instanceof Column\ClickHouse && $column->isLowCardinality) {
            $type = 'LowCardinality(' . $type . ')';
        }

        return $type;
    }

    protected function compileAutoIncrement(): string
    {
        return '';
    }

    protected function compileUnsigned(): string
    {
        return '';
    }

    protected function compileColumnDefinition(Column $column): string
    {
        $parts = [
            $this->quoteLiteral($column->name),
            $this->compileColumnType($column),
        ];

        if ($column->defaultRaw !== null) {
            $parts[] = 'DEFAULT ' . $column->defaultRaw;
        } elseif ($column->hasDefault) {
            $parts[] = 'DEFAULT ' . $this->compileDefaultValue($column->default);
        }

        if ($column instanceof Column\ClickHouse && $column->codecs !== []) {
            $parts[] = 'CODEC(' . \implode(', ', $column->codecs) . ')';
        }

        if ($column->ttl !== null) {
            $parts[] = 'TTL ' . $column->ttl;
        }

        if ($column->comment !== null) {
            $parts[] = "COMMENT '" . \str_replace(['\\', "'"], ['\\\\', "''"], $column->comment) . "'";
        }

        return \implode(' ', $parts);
    }

    public function dropIndex(string $table, string $name): Statement
    {
        return new Statement(
            'ALTER TABLE ' . $this->quote($table)
            . ' DROP INDEX ' . $this->quote($name),
            [],
            executor: $this->executor,
        );
    }

    #[\Override]
    public function compileAlter(Table $table): Statement
    {
        $alterations = [];

        foreach ($table->columns as $column) {
            $keyword = $column->isModify ? 'MODIFY COLUMN' : 'ADD COLUMN';
            $alterations[] = $keyword . ' ' . $this->compileColumnDefinition($column);
        }

        foreach ($table->renameColumns as $rename) {
            $alterations[] = 'RENAME COLUMN ' . $this->quoteLiteral($rename->from)
                . ' TO ' . $this->quoteLiteral($rename->to);
        }

        foreach ($table->dropColumns as $col) {
            $alterations[] = 'DROP COLUMN ' . $this->quoteLiteral($col);
        }

        foreach ($table->dropIndexes as $name) {
            $alterations[] = 'DROP INDEX ' . $this->quote($name);
        }

        foreach ($table->indexes as $index) {
            if ($index->type !== IndexType::Index) {
                throw new UnsupportedException(
                    'Only data-skipping indexes (index()) are supported in ClickHouse ALTER TABLE.'
                );
            }
            $alterations[] = 'ADD ' . $this->compileSkipIndex($index);
        }

        if (! empty($table->settings)) {
            throw new UnsupportedException(
                'Table SETTINGS can only be set on CREATE TABLE; emit `ALTER TABLE ... MODIFY SETTING` directly to change them.'
            );
        }

        if (empty($alterations)) {
            throw new ValidationException('ALTER TABLE requires at least one alteration.');
        }

        $sql = 'ALTER TABLE ' . $this->quote($table->name)
            . ' ' . \implode(', ', $alterations);

        return new Statement($sql, [], executor: $this->executor);
    }

    #[\Override]
    public function compileCreate(Table $table, bool $ifNotExists = false): Statement
    {
        $columnDefs = [];
        $primaryKeys = [];

        foreach ($table->columns as $column) {
            $def = $this->compileColumnDefinition($column);
            $columnDefs[] = $def;

            if ($column->isPrimary) {
                $primaryKeys[] = $this->quoteLiteral($column->name);
            }
        }

        if (! empty($table->compositePrimaryKey) && ! empty($primaryKeys)) {
            throw new ValidationException('Cannot combine column-level primary() with Table::primary() composite key.');
        }

        if (empty($primaryKeys) && ! empty($table->compositePrimaryKey)) {
            $primaryKeys = \array_map(fn (string $c): string => $this->quoteLiteral($c), $table->compositePrimaryKey);
        }

        foreach ($table->rawColumnDefs as $rawDef) {
            $columnDefs[] = $rawDef;
        }

        foreach ($table->indexes as $index) {
            if ($index->type !== IndexType::Index) {
                throw new UnsupportedException(
                    'Only data-skipping indexes (index()) are supported in ClickHouse CREATE TABLE.'
                );
            }
            $columnDefs[] = $this->compileSkipIndex($index);
        }

        $engine = $table->engine ?? Engine::MergeTree;

        $sql = 'CREATE TABLE ' . ($ifNotExists ? 'IF NOT EXISTS ' : '') . $this->quote($table->name)
            . ' (' . \implode(', ', $columnDefs) . ')'
            . ' ENGINE = ' . $this->compileEngine($engine, $table->engineArgs);

        if ($table->partitionExpression !== '') {
            $sql .= ' PARTITION BY ' . $table->partitionExpression;
        }

        if ($engine->requiresOrderBy()) {
            if ($table instanceof Table\ClickHouse && $table->orderByRaw !== null) {
                $sql .= ' ORDER BY ' . $table->orderByRaw;
            } else {
                $orderBy = ! empty($table->orderBy)
                    ? \array_map(fn (string $c): string => $this->quoteLiteral($c), $table->orderBy)
                    : $primaryKeys;

                $sql .= ! empty($orderBy)
                    ? ' ORDER BY (' . \implode(', ', $orderBy) . ')'
                    : ' ORDER BY tuple()';
            }
        }

        if ($table instanceof Table\ClickHouse && $table->sampleBy !== null) {
            if (! $engine->requiresOrderBy()) {
                throw new UnsupportedException(
                    'SAMPLE BY is only supported on engines that take an ORDER BY clause.'
                );
            }
            $sql .= ' SAMPLE BY ' . $table->sampleBy;
        }

        if ($table->ttl !== null) {
            $sql .= ' TTL ' . $table->ttl;
        }

        if (! empty($table->settings)) {
            $kv = [];
            foreach ($table->settings as $k => $v) {
                $kv[] = $k . ' = ' . $v;
            }
            $sql .= ' SETTINGS ' . \implode(', ', $kv);
        }

        return new Statement($sql, [], executor: $this->executor);
    }

    /**
     * Render a full `INDEX <name> <columns> TYPE <algorithm>[(args)] GRANULARITY <n>`
     * fragment, used by both CREATE TABLE and ALTER TABLE ADD INDEX.
     *
     * Defaults to `TYPE minmax GRANULARITY 3` when no algorithm is set on the
     * index — matches the ClickHouse default behaviour for callers using the
     * generic `Table::index()` without picking an algorithm.
     */
    private function compileSkipIndex(Index $index): string
    {
        $cols = \array_map(fn (string $c): string => $this->quoteLiteral($c), $index->columns);
        $expr = \count($cols) === 1 ? $cols[0] : '(' . \implode(', ', $cols) . ')';

        if ($index->algorithm === null) {
            return 'INDEX ' . $this->quote($index->name) . ' ' . $expr
                . ' TYPE minmax GRANULARITY ' . ($index->granularity ?? 3);
        }

        $type = $index->algorithm->value;

        if ($index->algorithmArgs !== []) {
            $args = \array_map(
                fn (string|int|float $arg): string => match (true) {
                    \is_string($arg) => "'" . \str_replace("'", "''", $arg) . "'",
                    // sprintf('%F', ...) avoids scientific notation (e.g. 1.0E-5)
                    // which ClickHouse rejects in index type arguments. Trim
                    // trailing zeros so 0.01 stays "0.010000" → "0.01".
                    \is_float($arg) => \rtrim(\rtrim(\sprintf('%F', $arg), '0'), '.'),
                    default => (string) $arg,
                },
                $index->algorithmArgs,
            );

            $type .= '(' . \implode(', ', $args) . ')';
        }

        return 'INDEX ' . $this->quote($index->name) . ' ' . $expr
            . ' TYPE ' . $type . ' GRANULARITY ' . ($index->granularity ?? 1);
    }

    /**
     * Compile an engine declaration: `<Name>` or `<Name>(<args...>)`.
     *
     * Identifier-type args (version column, sign column, column lists) are
     * quoted. Zookeeper path and replica name for ReplicatedMergeTree are
     * emitted as single-quoted string literals.
     *
     * @param  list<string>  $args
     */
    private function compileEngine(Engine $engine, array $args): string
    {
        return match ($engine) {
            Engine::MergeTree,
            Engine::AggregatingMergeTree => $engine->value . '()',

            Engine::ReplacingMergeTree => $engine->value . '('
                . (isset($args[0]) ? $this->quoteLiteral($args[0]) : '')
                . ')',

            Engine::SummingMergeTree => $engine->value . '('
                . (empty($args)
                    ? ''
                    : \implode(', ', \array_map(fn (string $c): string => $this->quoteLiteral($c), $args)))
                . ')',

            Engine::CollapsingMergeTree => $engine->value . '(' . $this->quoteLiteral($args[0]) . ')',

            Engine::ReplicatedMergeTree => $engine->value
                . "('" . \str_replace("'", "''", $args[0]) . "'"
                . ", '" . \str_replace("'", "''", $args[1]) . "')",

            Engine::Memory,
            Engine::Log,
            Engine::TinyLog,
            Engine::StripeLog => $engine->value,
        };
    }

    /**
     * Compile an element type for use inside `Array(T)` or `Tuple(...)`.
     *
     * Element types come from the {@see ColumnType} enum directly, so they
     * lack the per-column state (precision, unsigned flag, etc.) that
     * {@see compileColumnType()} relies on. This helper falls back to the
     * parent column's `isUnsigned` flag for integer elements and to the
     * parent's `precision` for `Decimal` elements so callers can spell common
     * shapes (`Array(UInt64)`, `Array(Decimal(18, 3))`) without leaking the
     * inner-type complexity into the public API.
     */
    private function compileNestedElementType(ColumnType $element, Column $parent): string
    {
        return match ($element) {
            ColumnType::String, ColumnType::Varchar, ColumnType::Relationship,
            ColumnType::Text, ColumnType::MediumText, ColumnType::LongText,
            ColumnType::Json, ColumnType::Object, ColumnType::Binary => 'String',
            ColumnType::TinyInteger => $parent->isUnsigned ? 'UInt8' : 'Int8',
            ColumnType::SmallInteger => $parent->isUnsigned ? 'UInt16' : 'Int16',
            ColumnType::Integer => $parent->isUnsigned ? 'UInt32' : 'Int32',
            ColumnType::BigInteger, ColumnType::Id => $parent->isUnsigned ? 'UInt64' : 'Int64',
            ColumnType::Float, ColumnType::Double => 'Float64',
            ColumnType::Decimal => 'Decimal(' . ($parent->precision ?? 10) . ', ' . ($parent->scale ?? 0) . ')',
            ColumnType::Boolean => 'UInt8',
            ColumnType::Datetime, ColumnType::Timestamp => $parent->precision
                ? 'DateTime64(' . $parent->precision . ')'
                : 'DateTime',
            ColumnType::Uuid => 'UUID',
            ColumnType::Uuid7 => 'FixedString(36)',
            ColumnType::Point => 'Tuple(Float64, Float64)',
            ColumnType::Linestring => 'Array(Tuple(Float64, Float64))',
            ColumnType::Polygon => 'Array(Array(Tuple(Float64, Float64)))',
            ColumnType::Vector => 'Array(Float64)',
            ColumnType::Enum, ColumnType::Serial, ColumnType::BigSerial,
            ColumnType::SmallSerial, ColumnType::Array, ColumnType::Tuple => throw new UnsupportedException(
                'Nested element type ' . $element->value . ' is not supported inside Array/Tuple.'
            ),
        };
    }

    /**
     * @param  string[]  $values
     */
    private function compileClickHouseEnum(array $values): string
    {
        $parts = [];
        foreach (\array_values($values) as $i => $value) {
            $parts[] = "'" . \str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "' = " . ($i + 1);
        }

        return 'Enum8(' . \implode(', ', $parts) . ')';
    }

    public function commentOnTable(string $table, string $comment): Statement
    {
        return new Statement(
            'ALTER TABLE ' . $this->quote($table) . " MODIFY COMMENT '" . str_replace(['\\', "'"], ['\\\\', "''"], $comment) . "'",
            [],
            executor: $this->executor,
        );
    }

    public function commentOnColumn(string $table, string $column, string $comment): Statement
    {
        return new Statement(
            'ALTER TABLE ' . $this->quote($table) . ' COMMENT COLUMN ' . $this->quoteLiteral($column) . " '" . str_replace(['\\', "'"], ['\\\\', "''"], $comment) . "'",
            [],
            executor: $this->executor,
        );
    }

    public function dropPartition(string $table, string $name): Statement
    {
        return new Statement(
            'ALTER TABLE ' . $this->quote($table) . " DROP PARTITION '" . str_replace(['\\', "'"], ['\\\\', "''"], $name) . "'",
            [],
            executor: $this->executor,
        );
    }
}
