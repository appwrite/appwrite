<?php

namespace Utopia\Query\Schema\Table;

use Utopia\Query\Exception\ValidationException;
use Utopia\Query\Schema\ClickHouse\Engine;
use Utopia\Query\Schema\Column;
use Utopia\Query\Schema\ColumnType;
use Utopia\Query\Schema\ForeignKey;
use Utopia\Query\Schema\Table;

/**
 * @extends Table<Column\ClickHouse, ForeignKey>
 */
class ClickHouse extends Table
{
    use Trait\ColumnAlterations;
    use Trait\CompositePrimary;

    /** ClickHouse SAMPLE BY expression. Emitted after ORDER BY when set. */
    public protected(set) ?string $sampleBy = null;

    /** Raw ORDER BY expression. Emitted verbatim when set; takes precedence over {@see $orderBy}. */
    public protected(set) ?string $orderByRaw = null;

    #[\Override]
    protected function newColumn(string $name, ColumnType $type, ?int $length = null, ?int $precision = null, ?int $scale = null, ?int $srid = null, ?int $dimensions = null, bool $autoIncrement = false): Column\ClickHouse
    {
        return new Column\ClickHouse($this, $name, $type, $length, $precision, $scale, $srid, $dimensions, $autoIncrement);
    }

    public function vector(string $name): Column\ClickHouse
    {
        $col = $this->newColumn($name, ColumnType::Vector);
        $this->columns[] = $col;

        return $col;
    }

    /**
     * Add an `Array(T)` column.
     *
     * The element type is taken from {@see ColumnType} and recursed back
     * through the standard column-type compiler, so `array('tags',
     * ColumnType::String)` emits `Array(String)`, `array('values',
     * ColumnType::BigInteger)->unsigned()` emits `Array(UInt64)`, etc.
     * Pair with `nullable()` and `lowCardinality()` exactly as with scalar
     * columns; ClickHouse's required wrapping order is applied by the compiler.
     */
    public function array(string $name, ColumnType $element): Column\ClickHouse
    {
        $col = $this->newColumn($name, ColumnType::Array);
        $col->asArray($element);
        $this->columns[] = $col;

        return $col;
    }

    /**
     * Add a `Tuple(t1, t2, ...)` column over the given element types.
     *
     * @param  list<ColumnType>  $elements
     *
     * @throws ValidationException if the element list is empty.
     */
    public function tuple(string $name, array $elements): Column\ClickHouse
    {
        $col = $this->newColumn($name, ColumnType::Tuple);
        $col->asTuple($elements);
        $this->columns[] = $col;

        return $col;
    }

    /**
     * Add a `FixedString(N)` column.
     *
     * Used for fixed-length string values whose byte length is known and
     * constant — ISO 3166 country codes, ISO 4217 currency codes, hash
     * digests, and similar values that benefit from ClickHouse's columnar
     * storage of fixed-width data.
     *
     * The column is registered with the generic `ColumnType::String` type and
     * tagged with FixedString state on {@see Column\ClickHouse}; the compiler
     * reads that state when emitting DDL, so the global `ColumnType` enum
     * stays free of ClickHouse-only cases.
     *
     * @throws ValidationException if $length is less than 1.
     */
    public function fixedString(string $name, int $length): Column\ClickHouse
    {
        $col = $this->newColumn($name, ColumnType::String, $length);
        $col->asFixedString($length);
        $this->columns[] = $col;

        return $col;
    }

    /**
     * Select the table engine. Engine-specific arguments are validated against
     * the engine variant:
     * - CollapsingMergeTree requires exactly one sign column.
     * - ReplicatedMergeTree requires a zookeeper path and replica name.
     *
     * @throws ValidationException if required engine arguments are missing.
     */
    public function engine(Engine $engine, string ...$args): static
    {
        if ($engine === Engine::CollapsingMergeTree && ! isset($args[0])) {
            throw new ValidationException('CollapsingMergeTree requires a sign column.');
        }

        if ($engine === Engine::ReplicatedMergeTree && (! isset($args[0]) || ! isset($args[1]))) {
            throw new ValidationException('ReplicatedMergeTree requires zookeeper_path and replica_name.');
        }

        $this->engine = $engine;
        $this->engineArgs = \array_values($args);

        return $this;
    }

    /**
     * Set the ORDER BY clause. When unset, ClickHouse falls back to the
     * primary key columns.
     *
     * @param  list<string>  $columns
     *
     * @throws ValidationException if any column name is not a valid identifier.
     */
    public function orderBy(array $columns): static
    {
        foreach ($columns as $column) {
            if (! \preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column)) {
                throw new ValidationException('Invalid column name in ORDER BY: ' . $column);
            }
        }

        $this->orderBy = $columns;

        return $this;
    }

    /**
     * Set the ORDER BY clause to a raw expression emitted verbatim.
     *
     * Mirrors {@see partitionBy()} — accepts the full parenthesised tuple,
     * including function calls (`toDate(ts)`, `cityHash64(user_id)`) that are
     * common in MergeTree ORDER BY clauses for sparse-index cardinality
     * control. The expression is emitted unquoted and must come from a trusted
     * (developer-controlled) source.
     *
     * Takes precedence over {@see orderBy()} when both are set.
     *
     * @throws ValidationException if the expression is empty or contains ";".
     */
    public function orderByRaw(string $expression): static
    {
        $trimmed = \trim($expression);

        if ($trimmed === '') {
            throw new ValidationException('Raw ORDER BY expression must not be empty.');
        }

        if (\str_contains($trimmed, ';')) {
            throw new ValidationException('Raw ORDER BY expression must not contain ";".');
        }

        $this->orderByRaw = $trimmed;

        return $this;
    }

    /**
     * Attach a table-level TTL expression.
     *
     * @throws ValidationException if the expression is empty or contains a semicolon.
     */
    public function ttl(string $expression): static
    {
        $trimmed = \trim($expression);

        if ($trimmed === '') {
            throw new ValidationException('TTL expression must not be empty.');
        }

        if (\str_contains($trimmed, ';')) {
            throw new ValidationException('TTL expression must not contain ";".');
        }

        $this->ttl = $trimmed;

        return $this;
    }

    /**
     * Set table-level engine SETTINGS.
     *
     * Compiled as `SETTINGS k=v, ...` after the TTL clause. Booleans become
     * `1` / `0`, ints/floats are stringified, strings are passed through after
     * a conservative character allow-list check.
     *
     * Calling this method replaces previously-set settings.
     *
     * @param  array<string, string|int|float|bool>  $settings
     *
     * @throws ValidationException if any key is not a valid identifier or any
     *                             string value contains characters outside the
     *                             allow-list.
     */
    public function settings(array $settings): static
    {
        $sanitized = [];

        foreach ($settings as $key => $value) {
            if (! \preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                throw new ValidationException('Invalid setting name: ' . $key);
            }

            if (\is_bool($value)) {
                $sanitized[$key] = $value ? '1' : '0';
            } elseif (\is_int($value)) {
                $sanitized[$key] = (string) $value;
            } elseif (\is_float($value)) {
                $sanitized[$key] = \rtrim(\rtrim(\sprintf('%F', $value), '0'), '.');
            } elseif (\is_string($value)) {
                if (! \preg_match('/^[A-Za-z0-9_.\-+\/]+$/', $value)) {
                    throw new ValidationException(
                        'Invalid setting value for ' . $key . ': must match [A-Za-z0-9_.\-+/]+'
                    );
                }
                $sanitized[$key] = $value;
            } else {
                throw new ValidationException(
                    'Setting value for ' . $key . ' must be string, int, float, or bool.'
                );
            }
        }

        $this->settings = $sanitized;

        return $this;
    }

    /**
     * Partition the table by an expression. ClickHouse uses a single expression
     * (no Range/List/Hash distinction in the DDL) — the expression itself
     * determines the partition shape (e.g. `toYYYYMM(event_date)`).
     */
    public function partitionBy(string $expression): static
    {
        $this->partitionExpression = $expression;

        return $this;
    }

    /**
     * Set the SAMPLE BY expression. Emitted after ORDER BY at table creation
     * time. Required to model tables that need approximate-query support via
     * `SELECT ... SAMPLE k` on MergeTree-family engines.
     *
     * @throws ValidationException if the expression is empty or contains a semicolon.
     */
    public function sampleBy(string $expression): static
    {
        $trimmed = \trim($expression);

        if ($trimmed === '') {
            throw new ValidationException('SAMPLE BY expression must not be empty.');
        }

        if (\str_contains($trimmed, ';')) {
            throw new ValidationException('SAMPLE BY expression must not contain ";".');
        }

        $this->sampleBy = $trimmed;

        return $this;
    }
}
