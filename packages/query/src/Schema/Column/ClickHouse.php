<?php

namespace Utopia\Query\Schema\Column;

use Utopia\Query\Exception\ValidationException;
use Utopia\Query\Schema\Column;
use Utopia\Query\Schema\ColumnType;
use Utopia\Query\Schema\Forwarder;
use Utopia\Query\Schema\Table;

/**
 * @extends Column<Table\ClickHouse>
 */
class ClickHouse extends Column
{
    use Trait\Comment;
    use Forwarder\ClickHouse;

    public protected(set) bool $isLowCardinality = false;

    /** Length when the column should be emitted as `FixedString(N)`; null otherwise. */
    public protected(set) ?int $fixedStringLength = null;

    /** @var list<string> Column-level CODEC clauses, e.g. ['Delta(4)', 'LZ4'] */
    public protected(set) array $codecs = [];

    /** Element type when the column is emitted as `Array(T)`; null otherwise. */
    public protected(set) ?ColumnType $arrayElementType = null;

    /** @var list<ColumnType> Element types when the column is emitted as `Tuple(...)`. */
    public protected(set) array $tupleElementTypes = [];

    /**
     * Mark the column as `FixedString(N)`.
     *
     * Used by {@see Table\ClickHouse::fixedString()} to attach the
     * ClickHouse-specific FixedString width to a column whose generic
     * {@see \Utopia\Query\Schema\ColumnType} is `String`. The compiler reads
     * this state when emitting DDL.
     *
     * @throws ValidationException if $length is less than 1.
     */
    public function asFixedString(int $length): static
    {
        if ($length < 1) {
            throw new ValidationException('FixedString length must be at least 1.');
        }

        $this->fixedStringLength = $length;

        return $this;
    }

    public function isFixedString(): bool
    {
        return $this->fixedStringLength !== null;
    }

    /**
     * Mark the column as `Array(T)` wrapping the given element type.
     */
    public function asArray(ColumnType $element): static
    {
        $this->arrayElementType = $element;

        return $this;
    }

    public function isArray(): bool
    {
        return $this->arrayElementType !== null;
    }

    /**
     * Mark the column as `Tuple(t1, t2, ...)` over the given element types.
     *
     * @param  list<ColumnType>  $elements
     *
     * @throws ValidationException if the element list is empty.
     */
    public function asTuple(array $elements): static
    {
        if ($elements === []) {
            throw new ValidationException('Tuple() requires at least one element type.');
        }

        $this->tupleElementTypes = $elements;

        return $this;
    }

    public function isTuple(): bool
    {
        return $this->tupleElementTypes !== [];
    }

    /**
     * @param  list<string>  $columns
     *
     * @phpstan-return ($columns is array{} ? static : Table\ClickHouse)
     */
    public function primary(array $columns = []): static|Table
    {
        if ($columns === []) {
            $this->isPrimary = true;

            return $this;
        }

        return $this->table->primary($columns);
    }

    /**
     * Wrap the column type in `LowCardinality(...)`.
     *
     * Suitable for string columns with a small number of distinct values
     * (status enums, type discriminators, country codes). `Nullable` is
     * applied inside `LowCardinality` to match ClickHouse's required
     * wrapping order: `LowCardinality(Nullable(String))`.
     */
    public function lowCardinality(): static
    {
        $this->isLowCardinality = true;

        return $this;
    }

    /**
     * Append a column-level CODEC clause.
     *
     * Multiple calls accumulate and emit `CODEC(c1, c2, ...)`. Pass either
     * a bare codec name (`->codec('LZ4')`) or one with arguments
     * (`->codec('Delta(4)')`, `->codec('ZSTD(3)')`). The codec string is
     * emitted verbatim and must come from a trusted source.
     *
     * @throws ValidationException if the codec string is empty or contains
     *                             a semicolon.
     */
    public function codec(string $codec): static
    {
        $trimmed = \trim($codec);

        if ($trimmed === '') {
            throw new ValidationException('CODEC expression must not be empty.');
        }

        if (\str_contains($trimmed, ';')) {
            throw new ValidationException('CODEC expression must not contain ";".');
        }

        $this->codecs[] = $trimmed;

        return $this;
    }
    /**
     * Attach a column-level TTL expression.
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
}
