<?php

namespace Appwrite\Utopia\Database;

use Appwrite\Utopia\Database\Documents\Aggregate;
use Utopia\Database\Attribute;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Schema;
use Utopia\Database\Filter;
use Utopia\Database\Query;
use Utopia\Database\Storage;
use Utopia\Database\Validator\Query\Aggregate as AggregateValidator;
use Utopia\Query\Method;
use Utopia\Query\Schema\ColumnType;

/**
 * Database::aggregate() returns each row as the engine reads it: values as the driver fetches them, and groups under
 * their storage column, a joined one unqualified unless another group shares its name. This names and types each
 * row as a read of the same attributes returns them.
 */
final readonly class Aggregation
{
    private const array COUNTS = [
        Method::Count,
        Method::CountDistinct,
        Method::BitAnd,
        Method::BitOr,
        Method::BitXor,
    ];

    private const array STATISTICS = [
        Method::Avg,
        Method::Stddev,
        Method::StddevPop,
        Method::StddevSamp,
        Method::Variance,
        Method::VarPop,
        Method::VarSamp,
    ];

    private const array INTEGERS = [
        ColumnType::TinyInteger,
        ColumnType::SmallInteger,
        ColumnType::Integer,
        ColumnType::BigInteger,
        ColumnType::Serial,
        ColumnType::SmallSerial,
        ColumnType::BigSerial,
    ];

    private const array FLOATS = [
        ColumnType::Float,
        ColumnType::Double,
        ColumnType::Decimal,
    ];

    private const array DATETIMES = [
        ColumnType::Datetime,
        ColumnType::Timestamp,
    ];

    private const array OBJECTS = [
        ColumnType::Json,
        ColumnType::Object,
    ];

    /**
     * @param array<string, Method> $aggregates The method of each aggregate, keyed by the name a row returns it under
     * @param array<string, Attribute> $operands The attribute each aggregate reads, keyed by the name a row returns it under
     * @param array<string, string> $groups The name a read returns each group under, keyed as the queries name it
     * @param array<string, Attribute> $attributes The attribute of each group, keyed by the name a read returns it under
     */
    private function __construct(
        private array $aggregates,
        private array $operands,
        private array $groups,
        private array $attributes,
    ) {
    }

    /**
     * @param list<Attribute> $internal The attributes every document carries
     * @param Document $collection The collection read, with its attribute documents
     * @param array<string, Document> $joined The collection each join reads, keyed by join alias
     * @param array<Query> $queries The queries the rows were aggregated by
     */
    public static function from(array $internal, Document $collection, array $joined, array $queries): self
    {
        $schema = [];
        foreach ([...$internal, ...self::attributes($collection)] as $attribute) {
            $schema[$attribute->key] = $attribute;
        }
        foreach ($joined as $alias => $definition) {
            foreach ([...$internal, ...self::attributes($definition)] as $attribute) {
                $schema[$alias . '.' . $attribute->key] = $attribute;
            }
        }

        $aggregates = [];
        $operands = [];
        $groups = [];
        $attributes = [];
        foreach ($queries as $query) {
            $method = $query->getMethod();
            if ($method->isAggregate()) {
                $alias = self::alias($query);
                $aggregates[$alias] = $method;
                $operand = $schema[self::name($schema, $query->getAttribute())] ?? null;
                if ($operand !== null) {
                    $operands[$alias] = $operand;
                }
                continue;
            }

            if ($method !== Method::GroupBy) {
                continue;
            }

            foreach ($query->getValues() as $group) {
                if (!\is_string($group) || $group === '') {
                    continue;
                }

                $name = self::name($schema, $group);
                $groups[$group] = $name;
                if (isset($schema[$name])) {
                    $attributes[$name] = $schema[$name];
                }
            }
        }

        return new self($aggregates, $operands, $groups, $attributes);
    }

    /**
     * @param array<string, mixed> $row A row as Database::aggregate() returns it
     */
    public function decode(array $row): Aggregate
    {
        $names = $this->names(\array_keys($row));

        $document = new Aggregate();
        foreach ($row as $column => $value) {
            $method = $this->aggregates[$column] ?? null;
            if ($method !== null) {
                $document->setAttribute($column, $this->aggregate($method, $this->operands[$column] ?? null, $value));
                continue;
            }

            $name = $names[$column] ?? $column;
            $attribute = $this->attributes[$name] ?? null;
            $document->setAttribute($name, $attribute === null ? $value : self::cast($attribute, $value));
        }

        return $document;
    }

    /**
     * The name a read returns each group column of a row under. A column is named as the queries name its group, or
     * failing that by the group's storage column.
     *
     * @param list<string> $columns
     * @return array<string, string>
     */
    private function names(array $columns): array
    {
        $unclaimed = [];
        foreach ($columns as $column) {
            if (!isset($this->aggregates[$column])) {
                $unclaimed[$column] = true;
            }
        }

        $names = [];
        $pending = [];
        foreach ($this->groups as $group => $name) {
            if (isset($unclaimed[$group])) {
                $names[$group] = $name;
                unset($unclaimed[$group]);
            } else {
                $pending[$group] = $name;
            }
        }

        foreach ($pending as $group => $name) {
            $dot = \strpos($group, '.');
            $key = $dot === false ? $group : \substr($group, $dot + 1);
            foreach ([Storage::column($key), $key] as $column) {
                if (isset($unclaimed[$column])) {
                    $names[$column] = $name;
                    unset($unclaimed[$column]);
                    break;
                }
            }
        }

        return $names;
    }

    private function aggregate(Method $method, ?Attribute $operand, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match (true) {
            \in_array($method, self::COUNTS, true) => self::integer($value),
            \in_array($method, self::STATISTICS, true) => (float) $value,
            $method === Method::Sum => $operand !== null && \in_array($operand->type, self::INTEGERS, true)
                ? self::integer($value)
                : (float) $value,
            default => $operand === null ? $value : self::cast($operand, $value),
        };
    }

    /**
     * The name a read returns an attribute under: a joined one qualified by its join alias, though the queries may
     * name it bare when no other collection of the read declares it.
     *
     * @param array<string, Attribute> $schema
     */
    private static function name(array $schema, string $attribute): string
    {
        if (\str_contains($attribute, '.') || isset($schema[$attribute])) {
            return $attribute;
        }

        $suffix = '.' . $attribute;
        $matches = [];
        foreach (\array_keys($schema) as $name) {
            if (\str_ends_with($name, $suffix)) {
                $matches[] = $name;
            }
        }

        return \count($matches) === 1 ? $matches[0] : $attribute;
    }

    private static function cast(Attribute $attribute, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        $encoded = $attribute->array
            || \in_array($attribute->type, self::OBJECTS, true)
            || \in_array(Filter::Json->value, $attribute->filters, true);

        if ($encoded && \is_string($value)) {
            $value = \json_decode($value, true);
        }

        if (!$attribute->array || !\is_array($value)) {
            return self::scalar($attribute->type, $value);
        }

        return \array_map(static fn (mixed $node): mixed => self::scalar($attribute->type, $node), $value);
    }

    private static function scalar(ColumnType $type, mixed $value): mixed
    {
        if ($value === null || \is_array($value)) {
            return $value;
        }

        return match (true) {
            $type === ColumnType::Boolean => \is_bool($value) ? $value : \filter_var($value, FILTER_VALIDATE_BOOLEAN),
            $type === ColumnType::Id => (string) $value,
            \in_array($type, self::INTEGERS, true) => self::integer($value),
            \in_array($type, self::FLOATS, true) => (float) $value,
            \in_array($type, self::DATETIMES, true) => DateTime::formatTz((string) $value),
            default => $value,
        };
    }

    /**
     * MariaDB sums integers into DECIMAL, so a whole value can arrive as "7.0000"; one past the platform's integers
     * stays a float rather than wrap.
     */
    private static function integer(mixed $value): int|float
    {
        if (\is_int($value)) {
            return $value;
        }

        $integer = \filter_var($value, FILTER_VALIDATE_INT);
        if ($integer !== false) {
            return $integer;
        }

        $float = (float) $value;

        return \floor($float) === $float && \abs($float) < PHP_INT_MAX ? (int) $float : $float;
    }

    /**
     * The name Database::aggregate() returns an aggregate under when the query gives it none.
     */
    private static function alias(Query $query): string
    {
        if ($query->getAlias() !== '') {
            return $query->getAlias();
        }

        $method = $query->getMethod()->value;
        $attribute = $query->getAttribute();
        $alias = $attribute === '*' || $attribute === '' ? $method : $method . '_' . $attribute;

        return \substr((string) \preg_replace('/[^A-Za-z0-9_]/', '_', $alias), 0, AggregateValidator::MAX_ALIAS_LENGTH);
    }

    /**
     * @return list<Attribute>
     */
    private static function attributes(Document $collection): array
    {
        $attributes = [];
        foreach ($collection->getAttribute('attributes', []) as $attribute) {
            if (!$attribute instanceof Document) {
                continue;
            }

            try {
                $attributes[] = Attribute::fromDocument($attribute);
            } catch (Schema) {
                continue;
            }
        }

        return $attributes;
    }
}
