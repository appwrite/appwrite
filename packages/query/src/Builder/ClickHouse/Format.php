<?php

namespace Utopia\Query\Builder\ClickHouse;

use Utopia\Query\Exception\ValidationException;

/**
 * ClickHouse bulk-ingest format identifiers.
 *
 * The values map 1:1 to the names ClickHouse accepts after the `FORMAT`
 * keyword in an `INSERT INTO <table> FORMAT <name>` envelope. Each case
 * knows how to serialize a row iterable into the request body that
 * ClickHouse expects for that format.
 */
enum Format: string
{
    case JSONEachRow = 'JSONEachRow';
    case TabSeparated = 'TabSeparated';

    /**
     * Serialize an iterable of associative rows into the body payload for
     * this format. An empty iterable yields an empty string — ClickHouse
     * accepts an empty body as a zero-row insert.
     *
     * When `$columns` is null the column ordering is derived from the keys
     * of the first row encountered. Subsequent rows are serialized against
     * whatever shape they themselves carry — there is no cross-row
     * consistency check. The implications differ per format:
     *
     * - For positional formats (e.g. {@see Format::TabSeparated}) values
     *   are emitted in row-key order. If later rows reorder their keys the
     *   columns silently misalign with the envelope's column list. Pass
     *   `$columns` explicitly whenever row shapes are not guaranteed
     *   identical, or whenever the format is positional.
     * - For named formats (e.g. {@see Format::JSONEachRow}) key ordering
     *   does not affect correctness because each value is paired with its
     *   key in the wire format. `$columns` still acts as a projection
     *   filter: rows missing a listed column receive `null`, and row keys
     *   outside the list are dropped.
     *
     * @param  iterable<array<string, mixed>>  $rows
     * @param  list<string>|null  $columns  Optional explicit column ordering. When null, derived from the keys of the first row.
     */
    public function serialize(iterable $rows, ?array $columns = null): string
    {
        return match ($this) {
            self::JSONEachRow => $this->serializeJsonEachRow($rows, $columns),
            self::TabSeparated => $this->serializeTabSeparated($rows, $columns),
        };
    }

    /**
     * @param  iterable<array<string, mixed>>  $rows
     * @param  list<string>|null  $columns
     */
    private function serializeJsonEachRow(iterable $rows, ?array $columns): string
    {
        $lines = [];
        foreach ($rows as $row) {
            if ($columns !== null) {
                $ordered = [];
                foreach ($columns as $col) {
                    $ordered[$col] = $row[$col] ?? null;
                }
                $row = $ordered;
            }

            $lines[] = \json_encode(
                (object) $row,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        }

        return \implode("\n", $lines);
    }

    /**
     * @param  iterable<array<string, mixed>>  $rows
     * @param  list<string>|null  $columns
     */
    private function serializeTabSeparated(iterable $rows, ?array $columns): string
    {
        $lines = [];
        foreach ($rows as $row) {
            $values = [];

            if ($columns === null) {
                foreach ($row as $value) {
                    $values[] = $this->escapeTabSeparatedValue($value);
                }
            } else {
                foreach ($columns as $col) {
                    $values[] = $this->escapeTabSeparatedValue($row[$col] ?? null);
                }
            }

            $lines[] = \implode("\t", $values);
        }

        return \implode("\n", $lines);
    }

    private function escapeTabSeparatedValue(mixed $value): string
    {
        if ($value === null) {
            return '\\N';
        }

        if (\is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }

        if (! \is_string($value)) {
            if (\is_object($value) && \method_exists($value, '__toString')) {
                $value = (string) $value;
            } else {
                throw new ValidationException('TabSeparated values must be scalar, null, or stringable. Received: ' . \get_debug_type($value));
            }
        }

        return \strtr($value, [
            '\\' => '\\\\',
            "\t" => '\\t',
            "\n" => '\\n',
            "\r" => '\\r',
        ]);
    }
}
