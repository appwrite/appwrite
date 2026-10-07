<?php

namespace Appwrite\Utopia\Database;

use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Structure as StructureException;
use Utopia\Database\Operator;

/**
 * Column checks for operator writes and increment/decrement.
 *
 * A normal update validates the new value against the column (type, min/max,
 * element size). Operators and the increment/decrement helpers skip that and
 * write SQL directly, so the next update then rejects the row. This predicts
 * the stored value and runs it through {@see ColumnRules} first.
 */
class Operators
{
    /**
     * @param array<string, mixed> $data
     */
    public static function has(array $data): bool
    {
        foreach ($data as $key => $value) {
            if (\is_string($key) && $value instanceof Operator) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param Document|array<string, mixed>|null $attribute
     */
    public static function find(Document $collection, string $key): Document|array|null
    {
        foreach ($collection->getAttribute('attributes', []) as $attribute) {
            if (self::name($attribute) === $key) {
                return $attribute;
            }
        }

        return null;
    }

    /**
     * Reject operator results that a plain update of the same value would reject.
     * On a 64-bit integer column, also records the column ceiling on the operator
     * so the library's 32-bit overflow check does not reject a value the column can hold.
     *
     * @param array<string, mixed> $data
     * @throws StructureException
     */
    public static function prepare(Document $collection, Document $current, array &$data): void
    {
        foreach ($data as $key => $value) {
            if (!\is_string($key) || \str_starts_with($key, '$') || !$value instanceof Operator) {
                continue;
            }

            $attribute = self::find($collection, $key);
            if ($attribute === null || self::type($attribute) === Database::VAR_RELATIONSHIP) {
                continue;
            }

            $predicted = self::predict($current->getAttribute($key), $value);
            if ($predicted === null && !self::checks($value)) {
                continue;
            }

            $predicted = self::wholeInteger(self::type($attribute), $predicted);
            $violation = ColumnRules::describe($attribute, $predicted);
            if ($violation !== null) {
                throw new StructureException($violation);
            }

            self::relaxIntegerCeiling($attribute, $value, $predicted);
        }
    }

    /**
     * Stricter of the request clamp and the column bound, after the result has
     * passed the column rules. A null current value stays null in SQL (`NULL + n`),
     * so only the operand type is checked.
     *
     * @throws StructureException
     */
    public static function limit(
        Document $collection,
        Document $row,
        string $name,
        int|float $value,
        int|float|null $limit,
        bool $increase,
    ): int|float|null {
        $attribute = self::find($collection, $name);
        if ($attribute === null) {
            return $limit;
        }

        $spec = self::spec($attribute);
        if (!empty($spec['array']) || !self::isNumericType($spec['type'] ?? '')) {
            return $limit;
        }

        self::assertOperand($spec, $value);

        $current = $row->getAttribute($name);
        if ($current === null || !\is_numeric($current)) {
            return $limit;
        }

        $current = self::wholeInteger((string) $spec['type'], $current + 0);
        $predicted = $increase ? $current + $value : $current - $value;
        $predicted = self::wholeInteger((string) $spec['type'], $predicted);
        $violation = ColumnRules::describe($attribute, $predicted);
        if ($violation !== null) {
            throw new StructureException($violation);
        }

        $column = self::numericBound($spec, $increase ? 'max' : 'min');
        if ($column === null) {
            return $limit;
        }
        if ($limit === null) {
            return $column;
        }

        return $increase ? \min($limit, $column) : \max($limit, $column);
    }

    /**
     * @param Document|array<string, mixed> $attribute
     */
    public static function numericBound(Document|array $attribute, string $edge): int|float|null
    {
        $spec = self::spec($attribute);
        $options = $spec['formatOptions'];
        if (\array_key_exists($edge, $options)) {
            $bound = self::boundValue($options[$edge], (string) ($spec['type'] ?? ''));
            if ($bound !== null) {
                return $bound;
            }
        }

        return self::typeBound($spec, $edge);
    }

    /**
     * @param Document|array<string, mixed> $attribute
     */
    private static function name(Document|array $attribute): string
    {
        if ($attribute instanceof Document) {
            $key = $attribute->getAttribute('key', '');
            if (\is_string($key) && $key !== '') {
                return $key;
            }

            return $attribute->getId();
        }

        $key = $attribute['key'] ?? $attribute['$id'] ?? '';

        return \is_string($key) ? $key : '';
    }

    /**
     * @param Document|array<string, mixed> $attribute
     */
    private static function type(Document|array $attribute): string
    {
        if ($attribute instanceof Document) {
            return (string) $attribute->getAttribute('type', '');
        }

        return (string) ($attribute['type'] ?? '');
    }

    /**
     * @param Document|array<string, mixed> $attribute
     * @return array<string, mixed>
     */
    private static function spec(Document|array $attribute): array
    {
        if ($attribute instanceof Document) {
            $attribute = $attribute->getArrayCopy();
        }

        $options = $attribute['formatOptions'] ?? [];
        if ($options instanceof Document) {
            $options = $options->getArrayCopy();
        }

        $attribute['type'] = (string) ($attribute['type'] ?? '');
        $attribute['size'] = (int) ($attribute['size'] ?? 0);
        $attribute['signed'] = $attribute['signed'] ?? true;
        $attribute['array'] = (bool) ($attribute['array'] ?? false);
        $attribute['formatOptions'] = \is_array($options) ? $options : [];

        return $attribute;
    }

    /**
     * @param array<string, mixed> $attribute
     * @throws StructureException
     */
    private static function assertOperand(array $attribute, int|float $value): void
    {
        $bare = $attribute;
        $bare['format'] = '';
        $bare['formatOptions'] = [];
        $bare['array'] = false;
        $bare['required'] = false;

        $violation = ColumnRules::describe($bare, self::wholeInteger((string) $attribute['type'], $value));
        if ($violation !== null) {
            throw new StructureException($violation);
        }
    }

    private static function isNumericType(string $type): bool
    {
        return \in_array($type, [Database::VAR_INTEGER, Database::VAR_BIGINT, Database::VAR_FLOAT], true);
    }

    /**
     * Methods whose stored result this class can predict. Others are left to the library.
     */
    private static function checks(Operator $operator): bool
    {
        return \in_array($operator->getMethod(), [
            Operator::TYPE_INCREMENT,
            Operator::TYPE_DECREMENT,
            Operator::TYPE_MULTIPLY,
            Operator::TYPE_DIVIDE,
            Operator::TYPE_MODULO,
            Operator::TYPE_POWER,
            Operator::TYPE_ARRAY_APPEND,
            Operator::TYPE_ARRAY_PREPEND,
            Operator::TYPE_ARRAY_INSERT,
            Operator::TYPE_STRING_CONCAT,
            Operator::TYPE_STRING_REPLACE,
        ], true);
    }

    private static function predict(mixed $current, Operator $operator): mixed
    {
        if (!self::checks($operator)) {
            return null;
        }

        $method = $operator->getMethod();
        $values = $operator->getValues();

        switch ($method) {
            case Operator::TYPE_INCREMENT:
            case Operator::TYPE_DECREMENT:
            case Operator::TYPE_MULTIPLY:
            case Operator::TYPE_DIVIDE:
            case Operator::TYPE_MODULO:
            case Operator::TYPE_POWER:
                $base = self::numeric($current);
                $operand = self::numeric($values[0] ?? 0);
                $raw = match ($method) {
                    Operator::TYPE_DECREMENT => $base - $operand,
                    Operator::TYPE_MULTIPLY => $base * $operand,
                    Operator::TYPE_DIVIDE => (float) $operand !== 0.0 ? $base / $operand : $base,
                    Operator::TYPE_MODULO => (float) $operand !== 0.0 ? $base % $operand : $base,
                    Operator::TYPE_POWER => $base ** $operand,
                    default => $base + $operand,
                };

                $clamp = $values[1] ?? null;
                if (\is_numeric($clamp)) {
                    $kept = match ($method) {
                        Operator::TYPE_DECREMENT, Operator::TYPE_DIVIDE => $raw < $clamp,
                        Operator::TYPE_MODULO => false,
                        default => $raw > $clamp,
                    };
                    if ($kept) {
                        return $base;
                    }
                }

                return $raw;

            case Operator::TYPE_ARRAY_APPEND:
                return \array_merge(\is_array($current) ? $current : [], $values);

            case Operator::TYPE_ARRAY_PREPEND:
                return \array_merge($values, \is_array($current) ? $current : []);

            case Operator::TYPE_ARRAY_INSERT:
                $items = \is_array($current) ? $current : [];
                $index = (int) ($values[0] ?? 0);
                \array_splice($items, $index, 0, [$values[1] ?? null]);

                return $items;

            case Operator::TYPE_STRING_CONCAT:
                return (string) ($current ?? '') . (string) ($values[0] ?? '');

            case Operator::TYPE_STRING_REPLACE:
                return \str_replace(
                    (string) ($values[0] ?? ''),
                    (string) ($values[1] ?? ''),
                    (string) ($current ?? ''),
                );

            default:
                return null;
        }
    }

    /**
     * The operator validator compares every integer result with the 32-bit max.
     * A column that can hold a wider value gets that ceiling attached so the
     * check is skipped. The SQL clamp uses the same ceiling; the result was
     * already accepted against it, so the stored value does not change.
     *
     * @param Document|array<string, mixed> $attribute
     */
    private static function relaxIntegerCeiling(Document|array $attribute, Operator $operator, mixed $predicted): void
    {
        if (self::type($attribute) !== Database::VAR_INTEGER) {
            return;
        }

        $values = $operator->getValues();
        if (isset($values[1]) || !\is_int($predicted)) {
            return;
        }

        if ($predicted <= Database::MAX_INT && $predicted >= Database::MIN_INT) {
            return;
        }

        $edge = match ($operator->getMethod()) {
            Operator::TYPE_DECREMENT, Operator::TYPE_DIVIDE => 'min',
            default => 'max',
        };
        $ceiling = self::numericBound($attribute, $edge);
        if ($ceiling === null) {
            return;
        }

        $operator->setValues([$values[0] ?? 1, $ceiling]);
    }

    private static function numeric(mixed $value): int|float
    {
        if (\is_int($value) || \is_float($value)) {
            return $value;
        }
        if (\is_string($value) && \is_numeric($value)) {
            return $value + 0;
        }

        return 0;
    }

    /**
     * Integer columns reject a float, including a whole number such as 10/2.
     * Collapse those to int so the type check matches what SQL stores.
     */
    private static function wholeInteger(string $type, mixed $value): mixed
    {
        if (!\in_array($type, [Database::VAR_INTEGER, Database::VAR_BIGINT], true)) {
            return $value;
        }
        if (!\is_float($value) || !\is_finite($value)) {
            return $value;
        }
        if (\floor($value) != $value || $value < \PHP_INT_MIN || $value > \PHP_INT_MAX) {
            return $value;
        }

        return (int) $value;
    }

    private static function boundValue(mixed $raw, string $type): int|float|null
    {
        if (!\is_numeric($raw)) {
            return null;
        }
        if ($type === Database::VAR_FLOAT) {
            return (float) $raw;
        }

        $number = self::wholeInteger(Database::VAR_INTEGER, \is_string($raw) ? $raw + 0 : $raw);
        if (\is_int($number)) {
            return $number;
        }
        if (\is_float($number)) {
            return $number;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $attribute
     */
    private static function typeBound(array $attribute, string $edge): int|float|null
    {
        $type = (string) ($attribute['type'] ?? '');
        if (!\in_array($type, [Database::VAR_INTEGER, Database::VAR_BIGINT], true)) {
            return null;
        }

        $signed = (bool) ($attribute['signed'] ?? true);
        $wide = $type === Database::VAR_BIGINT || (int) ($attribute['size'] ?? 0) >= 8;
        $max = $wide ? \PHP_INT_MAX : Database::MAX_INT;
        $min = $signed ? ($wide ? \PHP_INT_MIN : Database::MIN_INT) : 0;

        return $edge === 'max' ? $max : $min;
    }
}
