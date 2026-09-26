<?php

namespace Utopia\Query;

use JsonException;
use Utopia\Query\Builder\ParsedQuery;
use Utopia\Query\Exception as QueryException;
use Utopia\Query\Exception\ValidationException;

/** @phpstan-consistent-constructor */
class Query
{
    public const DEFAULT_ALIAS = 'main';

    /**
     * @var list<Method>
     */
    protected const array LOGICAL_TYPES = [
        Method::And,
        Method::Or,
        Method::ElemMatch,
    ];

    protected Method $method;

    protected string $attribute = '';

    protected string $attributeType = '';

    protected bool $onArray = false;

    /**
     * @var array<mixed>
     */
    protected array $values = [];

    /**
     * Construct a new query object
     *
     * @param  array<mixed>  $values
     */
    public function __construct(Method|string $method, string $attribute = '', array $values = [])
    {
        $this->method = $method instanceof Method ? $method : Method::from($method);
        $this->attribute = $attribute;
        $this->values = $values;
    }

    public function __clone(): void
    {
        foreach ($this->values as $index => $value) {
            if ($value instanceof self) {
                $this->values[$index] = clone $value;
            }
        }
    }

    public function getMethod(): Method
    {
        return $this->method;
    }

    public function getAttribute(): string
    {
        return $this->attribute;
    }

    /**
     * @return array<mixed>
     */
    public function getValues(): array
    {
        return $this->values;
    }

    public function getValue(mixed $default = null): mixed
    {
        return $this->values[0] ?? $default;
    }

    public function isNestedJoin(): bool
    {
        if (! $this->method->isJoin()) {
            return false;
        }

        foreach ($this->values as $value) {
            if ($value instanceof self) {
                return true;
            }
        }

        return false;
    }

    public function getJoinAlias(): string
    {
        if ($this->method === Method::CrossJoin || $this->method === Method::NaturalJoin) {
            $alias = $this->values[0] ?? '';

            return \is_string($alias) ? $alias : '';
        }

        if ($this->isNestedJoin()) {
            $first = $this->values[0] ?? null;

            return \is_string($first) ? $first : '';
        }

        $alias = $this->values[3] ?? '';

        return \is_string($alias) ? $alias : '';
    }

    /**
     * @return list<self>
     */
    public function getJoinOnQueries(): array
    {
        if (! $this->isNestedJoin()) {
            return [];
        }

        $queries = [];
        foreach ($this->values as $value) {
            if ($value instanceof self) {
                $queries[] = $value;
            }
        }

        return $queries;
    }

    /**
     * Sets method
     */
    public function setMethod(Method|string $method): static
    {
        $this->method = $method instanceof Method ? $method : Method::from($method);

        return $this;
    }

    /**
     * Sets attribute
     */
    public function setAttribute(string $attribute): static
    {
        $this->attribute = $attribute;

        return $this;
    }

    /**
     * Sets values
     *
     * @param  array<mixed>  $values
     */
    public function setValues(array $values): static
    {
        $this->values = $values;

        return $this;
    }

    /**
     * Sets value
     */
    public function setValue(mixed $value): static
    {
        $this->values = [$value];

        return $this;
    }

    /**
     * Check if method is supported
     */
    public static function isMethod(string $value): bool
    {
        return Method::tryFrom($value) !== null;
    }

    /**
     * Check if method is a spatial-only query method
     */
    public function isSpatialQuery(): bool
    {
        return $this->method->isSpatial();
    }

    /**
     * Parse query from a JSON string.
     *
     * Raw queries (`Method::Raw`) are rejected by default: they bypass the
     * binding/escaping pipeline and are a known SQL injection vector when
     * the JSON comes from an untrusted source. Set `$allowRaw = true` only
     * when the JSON is trusted (e.g. round-tripped from an in-process cache).
     *
     * @throws QueryException
     */
    public static function parse(string $query, bool $allowRaw = false): static
    {
        try {
            $query = \json_decode($query, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new QueryException('Invalid query: '.$e->getMessage());
        }

        if (! \is_array($query)) {
            throw new QueryException('Invalid query. Must be an array, got '.\gettype($query));
        }

        /** @var array<string, mixed> $query */
        return static::parseQuery($query, $allowRaw);
    }

    /**
     * Parse query from a decoded array.
     *
     * Raw queries (`Method::Raw`) are rejected by default — see `parse()`.
     *
     * @param  array<string, mixed>  $query
     *
     * @throws QueryException
     */
    public static function parseQuery(array $query, bool $allowRaw = false): static
    {
        $method = $query['method'] ?? '';
        $attribute = $query['attribute'] ?? '';
        $values = $query['values'] ?? [];

        if (! \is_string($method)) {
            throw new QueryException('Invalid query method. Must be a string, got '.\gettype($method));
        }

        if (! static::isMethod($method)) {
            throw new QueryException('Invalid query method: '.$method);
        }

        if (! \is_string($attribute)) {
            throw new QueryException('Invalid query attribute. Must be a string, got '.\gettype($attribute));
        }

        if (! \is_array($values)) {
            throw new QueryException('Invalid query values. Must be an array, got '.\gettype($values));
        }

        $methodEnum = Method::from($method);

        if ($methodEnum === Method::Raw && ! $allowRaw) {
            throw new ValidationException('Raw queries cannot be parsed from untrusted input; construct via Query::raw() in code');
        }

        if ($methodEnum->isNested()) {
            foreach ($values as $index => $value) {
                /** @var array<string, mixed> $value */
                $values[$index] = static::parseQuery($value, $allowRaw);
            }
        } elseif ($methodEnum->isJoin()) {
            foreach ($values as $index => $value) {
                if (\is_array($value) && isset($value['method']) && \is_string($value['method'])) {
                    /** @var array<string, mixed> $value */
                    $values[$index] = static::parseQuery($value, $allowRaw);
                }
            }
        }

        return new static($methodEnum, $attribute, $values);
    }

    /**
     * Parse an array of queries.
     *
     * Raw queries (`Method::Raw`) are rejected by default — see `parse()`.
     *
     * @param  array<string>  $queries
     * @return array<static>
     *
     * @throws QueryException
     */
    public static function parseQueries(array $queries, bool $allowRaw = false): array
    {
        $parsed = [];

        foreach ($queries as $query) {
            $parsed[] = static::parse($query, $allowRaw);
        }

        return $parsed;
    }

    /**
     * Compute a shape-only fingerprint of an array of queries.
     *
     * The fingerprint captures the structure of the queries — method and
     * attribute — without values. Two query sets with the same shape but
     * different parameter values produce the same fingerprint, which is
     * useful for pattern-based counting and slow-query grouping.
     *
     * Logical queries (`and`, `or`, `elemMatch`) contribute their inner
     * structure to the hash via `Query::shape()` — two `and(...)` queries
     * with different child shapes produce different fingerprints.
     *
     * Accepts either raw query strings or parsed Query objects.
     *
     * @param  array<mixed>  $queries raw query strings or Query instances
     * @return string md5 hash of the canonical shape
     *
     * @throws QueryException if an element is neither a string nor a Query
     */
    public static function fingerprint(array $queries): string
    {
        $shapes = [];

        foreach ($queries as $query) {
            if (\is_string($query)) {
                $query = static::parse($query);
            }

            if (! $query instanceof self) {
                throw new QueryException('Invalid query element for fingerprint: expected string or Query instance');
            }

            $shapes[] = $query->shape();
        }

        \sort($shapes);

        return \md5(\implode('|', $shapes));
    }

    /**
     * Canonical shape string for this Query — values excluded.
     *
     * Non-logical queries produce `method:attribute`. Logical queries
     * (`and`, `or`, `elemMatch`) produce `method:attribute(child1|child2|…)`
     * with children sorted so child order does not affect the shape.
     *
     * Implemented iteratively: walks the tree into a preorder list via a
     * stack, then processes the reversed list so each node's children are
     * always resolved before the node itself.
     */
    public function shape(): string
    {
        // 1. Preorder flatten the tree.
        $nodes = [];
        $stack = [$this];
        while ($stack) {
            /** @var self $node */
            $node = \array_pop($stack);
            $nodes[] = $node;

            if (! \in_array($node->method, self::LOGICAL_TYPES, true) && ! $node->isNestedJoin()) {
                continue;
            }
            foreach ($node->values as $child) {
                if ($child instanceof self) {
                    $stack[] = $child;
                }
            }
        }

        // 2. Process reversed so children are always shaped before parents.
        $shapes = [];
        foreach (\array_reverse($nodes) as $node) {
            $id = \spl_object_id($node);

            if (! \in_array($node->method, self::LOGICAL_TYPES, true) && ! $node->isNestedJoin()) {
                $shapes[$id] = $node->method->value.':'.$node->attribute;

                continue;
            }

            $childShapes = [];
            foreach ($node->values as $child) {
                if ($child instanceof self) {
                    $childShapes[] = $shapes[\spl_object_id($child)];
                }
            }
            \sort($childShapes);

            // Attribute is empty for and/or; meaningful for elemMatch (the field being matched).
            $shapes[$id] = $node->method->value.':'.$node->attribute.'('.\implode('|', $childShapes).')';
        }

        return $shapes[\spl_object_id($this)];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $array = ['method' => $this->method->value];

        if (! empty($this->attribute)) {
            $array['attribute'] = $this->attribute;
        }

        $array['values'] = [];
        foreach ($this->values as $value) {
            $array['values'][] = $value instanceof self ? $value->toArray() : $value;
        }

        return $array;
    }

    /**
     * Compile this query using the given compiler
     */
    public function compile(Compiler $compiler): string
    {
        return match ($this->method) {
            Method::OrderAsc,
            Method::OrderDesc,
            Method::OrderRandom => $compiler->compileOrder($this),
            Method::Limit => $compiler->compileLimit($this),
            Method::Offset => $compiler->compileOffset($this),
            Method::CursorAfter,
            Method::CursorBefore => $compiler->compileCursor($this),
            Method::Select => $compiler->compileSelect($this),
            Method::Count,
            Method::CountDistinct,
            Method::Sum,
            Method::Avg,
            Method::Min,
            Method::Max,
            Method::Stddev,
            Method::StddevPop,
            Method::StddevSamp,
            Method::Variance,
            Method::VarPop,
            Method::VarSamp,
            Method::BitAnd,
            Method::BitOr,
            Method::BitXor => $compiler->compileAggregate($this),
            Method::GroupBy,
            Method::GroupByTimeBucket => $compiler->compileGroupBy($this),
            Method::Join,
            Method::LeftJoin,
            Method::RightJoin,
            Method::CrossJoin,
            Method::FullOuterJoin,
            Method::NaturalJoin => $compiler->compileJoin($this),
            Method::Having => $compiler->compileFilter($this),
            default => $compiler->compileFilter($this),
        };
    }

    /**
     * @throws QueryException
     */
    public function toString(): string
    {
        try {
            return \json_encode($this->toArray(), flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new QueryException('Invalid Json: '.$e->getMessage());
        }
    }

    /**
     * Helper method to create Query with equal method
     *
     * @param  array<string|int|float|bool|null|array<mixed,mixed>>  $values
     */
    public static function equal(string $attribute, array $values): static
    {
        return new static(Method::Equal, $attribute, $values);
    }

    /**
     * Helper method to create Query with notEqual method
     *
     * @param  string|int|float|bool|null|array<mixed,mixed>  $value
     */
    public static function notEqual(string $attribute, string|int|float|bool|array|null $value): static
    {
        // maps or not an array
        if ((is_array($value) && ! array_is_list($value)) || ! is_array($value)) {
            $value = [$value];
        }

        return new static(Method::NotEqual, $attribute, $value);
    }

    /**
     * Helper method to create Query with lessThan method
     */
    public static function lessThan(string $attribute, string|int|float|bool $value): static
    {
        return new static(Method::LessThan, $attribute, [$value]);
    }

    /**
     * Helper method to create Query with lessThanEqual method
     */
    public static function lessThanEqual(string $attribute, string|int|float|bool $value): static
    {
        return new static(Method::LessThanEqual, $attribute, [$value]);
    }

    /**
     * Helper method to create Query with greaterThan method
     */
    public static function greaterThan(string $attribute, string|int|float|bool $value): static
    {
        return new static(Method::GreaterThan, $attribute, [$value]);
    }

    /**
     * Helper method to create Query with greaterThanEqual method
     */
    public static function greaterThanEqual(string $attribute, string|int|float|bool $value): static
    {
        return new static(Method::GreaterThanEqual, $attribute, [$value]);
    }

    /**
     * Helper method to create Query with contains method.
     *
     * @param  array<mixed>  $values
     *
     * @deprecated Use containsString() for string substring matching or containsAny() for array attributes.
     */
    #[\Deprecated('Use containsString() for string substring matching or containsAny() for array attributes.')]
    public static function contains(string $attribute, array $values): static
    {
        return new static(Method::Contains, $attribute, $values);
    }

    /**
     * Helper method to create Query for string substring matching.
     * Compiles to LIKE '%value%' for each given value.
     *
     * @param  array<mixed>  $values
     */
    public static function containsString(string $attribute, array $values): static
    {
        return new static(Method::Contains, $attribute, $values);
    }

    /**
     * Helper method to create Query with containsAny method.
     * For array and relationship attributes, matches documents where the attribute contains ANY of the given values.
     *
     * @param  array<mixed>  $values
     */
    public static function containsAny(string $attribute, array $values): static
    {
        return new static(Method::ContainsAny, $attribute, $values);
    }

    /**
     * Helper method to create Query with notContains method
     *
     * @param  array<mixed>  $values
     */
    public static function notContains(string $attribute, array $values): static
    {
        return new static(Method::NotContains, $attribute, $values);
    }

    /**
     * Helper method to create Query with between method
     */
    public static function between(string $attribute, string|int|float|bool $start, string|int|float|bool $end): static
    {
        return new static(Method::Between, $attribute, [$start, $end]);
    }

    /**
     * Helper method to create Query with notBetween method
     */
    public static function notBetween(string $attribute, string|int|float|bool $start, string|int|float|bool $end): static
    {
        return new static(Method::NotBetween, $attribute, [$start, $end]);
    }

    /**
     * Helper method to create Query with search method
     */
    public static function search(string $attribute, string $value): static
    {
        return new static(Method::Search, $attribute, [$value]);
    }

    /**
     * Helper method to create Query with notSearch method
     */
    public static function notSearch(string $attribute, string $value): static
    {
        return new static(Method::NotSearch, $attribute, [$value]);
    }

    /**
     * Helper method to create Query with select method
     *
     * @param  array<string>  $attributes
     */
    public static function select(array $attributes): static
    {
        return new static(Method::Select, values: $attributes);
    }

    /**
     * Helper method to create Query with orderDesc method
     */
    public static function orderDesc(string $attribute = '', ?NullsPosition $nulls = null): static
    {
        return new static(Method::OrderDesc, $attribute, $nulls !== null ? [$nulls] : []);
    }

    /**
     * Helper method to create Query with orderAsc method
     */
    public static function orderAsc(string $attribute = '', ?NullsPosition $nulls = null): static
    {
        return new static(Method::OrderAsc, $attribute, $nulls !== null ? [$nulls] : []);
    }

    /**
     * Helper method to create Query with orderRandom method
     */
    public static function orderRandom(): static
    {
        return new static(Method::OrderRandom);
    }

    /**
     * Helper method to create Query with limit method
     */
    public static function limit(int $value): static
    {
        return new static(Method::Limit, values: [$value]);
    }

    /**
     * Helper method to create Query with offset method
     */
    public static function offset(int $value): static
    {
        return new static(Method::Offset, values: [$value]);
    }

    /**
     * Helper method to create Query with cursorAfter method
     */
    public static function cursorAfter(mixed $value): static
    {
        return new static(Method::CursorAfter, values: [$value]);
    }

    /**
     * Helper method to create Query with cursorBefore method
     */
    public static function cursorBefore(mixed $value): static
    {
        return new static(Method::CursorBefore, values: [$value]);
    }

    /**
     * Helper method to create Query with isNull method
     */
    public static function isNull(string $attribute): static
    {
        return new static(Method::IsNull, $attribute);
    }

    /**
     * Helper method to create Query with isNotNull method
     */
    public static function isNotNull(string $attribute): static
    {
        return new static(Method::IsNotNull, $attribute);
    }

    public static function startsWith(string $attribute, string $value): static
    {
        return new static(Method::StartsWith, $attribute, [$value]);
    }

    public static function notStartsWith(string $attribute, string $value): static
    {
        return new static(Method::NotStartsWith, $attribute, [$value]);
    }

    public static function endsWith(string $attribute, string $value): static
    {
        return new static(Method::EndsWith, $attribute, [$value]);
    }

    public static function notEndsWith(string $attribute, string $value): static
    {
        return new static(Method::NotEndsWith, $attribute, [$value]);
    }

    /**
     * Helper method to create Query for documents created before a specific date
     */
    public static function createdBefore(string $value): static
    {
        return static::lessThan('$createdAt', $value);
    }

    /**
     * Helper method to create Query for documents created after a specific date
     */
    public static function createdAfter(string $value): static
    {
        return static::greaterThan('$createdAt', $value);
    }

    /**
     * Helper method to create Query for documents updated before a specific date
     */
    public static function updatedBefore(string $value): static
    {
        return static::lessThan('$updatedAt', $value);
    }

    /**
     * Helper method to create Query for documents updated after a specific date
     */
    public static function updatedAfter(string $value): static
    {
        return static::greaterThan('$updatedAt', $value);
    }

    /**
     * Helper method to create Query for documents created between two dates
     */
    public static function createdBetween(string $start, string $end): static
    {
        return static::between('$createdAt', $start, $end);
    }

    /**
     * Helper method to create Query for documents updated between two dates
     */
    public static function updatedBetween(string $start, string $end): static
    {
        return static::between('$updatedAt', $start, $end);
    }

    /**
     * @param  array<Query>  $queries
     */
    public static function or(array $queries): static
    {
        return new static(Method::Or, '', $queries);
    }

    /**
     * @param  array<Query>  $queries
     */
    public static function and(array $queries): static
    {
        return new static(Method::And, '', $queries);
    }

    /**
     * @param  array<mixed>  $values
     */
    public static function containsAll(string $attribute, array $values): static
    {
        return new static(Method::ContainsAll, $attribute, $values);
    }

    /**
     * Filters $queries for $types
     *
     * @param  array<static>  $queries
     * @param  array<Method>  $types
     * @return array<static>
     */
    public static function getByType(array $queries, array $types, bool $clone = true): array
    {
        $filtered = [];

        foreach ($queries as $query) {
            if (\in_array($query->getMethod(), $types, true)) {
                $filtered[] = $clone ? clone $query : $query;
            }
        }

        return $filtered;
    }

    /**
     * @param  array<static>  $queries
     * @return array<static>
     */
    public static function getCursorQueries(array $queries, bool $clone = true): array
    {
        return self::getByType(
            $queries,
            [
                Method::CursorAfter,
                Method::CursorBefore,
            ],
            $clone
        );
    }

    /**
     * Iterates through queries and groups them by type
     *
     * @param  array<mixed>  $queries
     */
    public static function groupByType(array $queries): ParsedQuery
    {
        $filters = [];
        $selections = [];
        $aggregations = [];
        $groupBy = [];
        $timeBuckets = [];
        $having = [];
        $distinct = false;
        $joins = [];
        $unions = [];
        $limit = null;
        $offset = null;
        $cursor = null;
        $cursorDirection = null;

        foreach ($queries as $query) {
            if (! $query instanceof Query) {
                continue;
            }

            $method = $query->getMethod();
            $values = $query->getValues();

            switch (true) {
                case $method === Method::OrderAsc:
                case $method === Method::OrderDesc:
                case $method === Method::OrderRandom:
                    // Ordering is compiled directly from the pending query list
                    // in Builder::compileOrderAndLimit; no aggregation needed
                    // here.
                    break;

                case $method === Method::Limit:
                    if ($limit === null && isset($values[0]) && \is_numeric($values[0])) {
                        $limit = \intval($values[0]);
                    }
                    break;

                case $method === Method::Offset:
                    if ($offset === null && isset($values[0]) && \is_numeric($values[0])) {
                        $offset = \intval($values[0]);
                    }
                    break;

                case $method === Method::CursorAfter:
                case $method === Method::CursorBefore:
                    if ($cursor === null) {
                        $cursor = $values[0] ?? null;
                        $cursorDirection = $method === Method::CursorAfter
                            ? CursorDirection::After
                            : CursorDirection::Before;
                    }
                    break;

                case $method === Method::Select:
                    $selections[] = clone $query;
                    break;

                case $method === Method::Count:
                case $method === Method::CountDistinct:
                case $method === Method::Sum:
                case $method === Method::Avg:
                case $method === Method::Min:
                case $method === Method::Max:
                case $method === Method::Stddev:
                case $method === Method::StddevPop:
                case $method === Method::StddevSamp:
                case $method === Method::Variance:
                case $method === Method::VarPop:
                case $method === Method::VarSamp:
                case $method === Method::BitAnd:
                case $method === Method::BitOr:
                case $method === Method::BitXor:
                    $aggregations[] = clone $query;
                    break;

                case $method === Method::GroupBy:
                    /** @var array<string> $values */
                    foreach ($values as $col) {
                        $groupBy[] = $col;
                    }
                    break;

                case $method === Method::GroupByTimeBucket:
                    /** @var string $interval */
                    $interval = $values[0] ?? '';
                    $timeBuckets[] = [
                        'attribute' => $query->getAttribute(),
                        'interval' => $interval,
                    ];
                    break;

                case $method === Method::Having:
                    $having[] = clone $query;
                    break;

                case $method === Method::Distinct:
                    $distinct = true;
                    break;

                case $method === Method::Join:
                case $method === Method::LeftJoin:
                case $method === Method::RightJoin:
                case $method === Method::CrossJoin:
                case $method === Method::FullOuterJoin:
                case $method === Method::NaturalJoin:
                    $joins[] = clone $query;
                    break;

                case $method === Method::Union:
                case $method === Method::UnionAll:
                    $unions[] = clone $query;
                    break;

                default:
                    $filters[] = clone $query;
                    break;
            }
        }

        return new ParsedQuery(
            filters: $filters,
            selections: $selections,
            aggregations: $aggregations,
            groupBy: $groupBy,
            having: $having,
            distinct: $distinct,
            joins: $joins,
            unions: $unions,
            limit: $limit,
            offset: $offset,
            cursor: $cursor,
            cursorDirection: $cursorDirection,
            timeBuckets: $timeBuckets,
        );
    }

    /**
     * Is this query able to contain other queries
     */
    public function isNested(): bool
    {
        return $this->method->isNested();
    }

    public function onArray(): bool
    {
        return $this->onArray;
    }

    public function setOnArray(bool $bool): void
    {
        $this->onArray = $bool;
    }

    public function setAttributeType(string $type): void
    {
        $this->attributeType = $type;
    }

    public function getAttributeType(): string
    {
        return $this->attributeType;
    }

    // Spatial query methods

    /**
     * Helper method to create Query with distanceEqual method
     *
     * @param  array<mixed>  $values
     */
    public static function distanceEqual(string $attribute, array $values, int|float $distance, bool $meters = false): static
    {
        return new static(Method::DistanceEqual, $attribute, [[$values, $distance, $meters]]);
    }

    /**
     * Helper method to create Query with distanceNotEqual method
     *
     * @param  array<mixed>  $values
     */
    public static function distanceNotEqual(string $attribute, array $values, int|float $distance, bool $meters = false): static
    {
        return new static(Method::DistanceNotEqual, $attribute, [[$values, $distance, $meters]]);
    }

    /**
     * Helper method to create Query with distanceGreaterThan method
     *
     * @param  array<mixed>  $values
     */
    public static function distanceGreaterThan(string $attribute, array $values, int|float $distance, bool $meters = false): static
    {
        return new static(Method::DistanceGreaterThan, $attribute, [[$values, $distance, $meters]]);
    }

    /**
     * Helper method to create Query with distanceLessThan method
     *
     * @param  array<mixed>  $values
     */
    public static function distanceLessThan(string $attribute, array $values, int|float $distance, bool $meters = false): static
    {
        return new static(Method::DistanceLessThan, $attribute, [[$values, $distance, $meters]]);
    }

    /**
     * Helper method to create Query with intersects method
     *
     * @param  array<mixed>  $values
     */
    public static function intersects(string $attribute, array $values): static
    {
        return new static(Method::Intersects, $attribute, [$values]);
    }

    /**
     * Helper method to create Query with notIntersects method
     *
     * @param  array<mixed>  $values
     */
    public static function notIntersects(string $attribute, array $values): static
    {
        return new static(Method::NotIntersects, $attribute, [$values]);
    }

    /**
     * Helper method to create Query with crosses method
     *
     * @param  array<mixed>  $values
     */
    public static function crosses(string $attribute, array $values): static
    {
        return new static(Method::Crosses, $attribute, [$values]);
    }

    /**
     * Helper method to create Query with notCrosses method
     *
     * @param  array<mixed>  $values
     */
    public static function notCrosses(string $attribute, array $values): static
    {
        return new static(Method::NotCrosses, $attribute, [$values]);
    }

    /**
     * Helper method to create Query with overlaps method
     *
     * @param  array<mixed>  $values
     */
    public static function overlaps(string $attribute, array $values): static
    {
        return new static(Method::Overlaps, $attribute, [$values]);
    }

    /**
     * Helper method to create Query with notOverlaps method
     *
     * @param  array<mixed>  $values
     */
    public static function notOverlaps(string $attribute, array $values): static
    {
        return new static(Method::NotOverlaps, $attribute, [$values]);
    }

    /**
     * Helper method to create Query with touches method
     *
     * @param  array<mixed>  $values
     */
    public static function touches(string $attribute, array $values): static
    {
        return new static(Method::Touches, $attribute, [$values]);
    }

    /**
     * Helper method to create Query with notTouches method
     *
     * @param  array<mixed>  $values
     */
    public static function notTouches(string $attribute, array $values): static
    {
        return new static(Method::NotTouches, $attribute, [$values]);
    }

    /**
     * Helper method to create Query with vectorDot method
     *
     * @param  array<float>  $vector
     */
    public static function vectorDot(string $attribute, array $vector): static
    {
        return new static(Method::VectorDot, $attribute, [$vector]);
    }

    /**
     * Helper method to create Query with vectorCosine method
     *
     * @param  array<float>  $vector
     */
    public static function vectorCosine(string $attribute, array $vector): static
    {
        return new static(Method::VectorCosine, $attribute, [$vector]);
    }

    /**
     * Helper method to create Query with vectorEuclidean method
     *
     * @param  array<float>  $vector
     */
    public static function vectorEuclidean(string $attribute, array $vector): static
    {
        return new static(Method::VectorEuclidean, $attribute, [$vector]);
    }

    /**
     * Helper method to create Query with regex method
     */
    public static function regex(string $attribute, string $pattern): static
    {
        return new static(Method::Regex, $attribute, [$pattern]);
    }

    /**
     * Helper method to create Query with exists method
     *
     * @param  array<string>  $attributes
     */
    public static function exists(array $attributes): static
    {
        return new static(Method::Exists, '', $attributes);
    }

    /**
     * Helper method to create Query with notExists method
     *
     * @param  string|int|float|bool|array<mixed,mixed>  $attribute
     */
    public static function notExists(string|int|float|bool|array $attribute): static
    {
        return new static(Method::NotExists, '', is_array($attribute) ? $attribute : [$attribute]);
    }

    /**
     * @param  array<Query>  $queries
     */
    public static function elemMatch(string $attribute, array $queries): static
    {
        return new static(Method::ElemMatch, $attribute, $queries);
    }

    // Aggregation factory methods

    public static function count(string $attribute = '*', string $alias = ''): static
    {
        return new static(Method::Count, $attribute, $alias !== '' ? [$alias] : []);
    }

    public static function countDistinct(string $attribute, string $alias = ''): static
    {
        return new static(Method::CountDistinct, $attribute, $alias !== '' ? [$alias] : []);
    }

    public static function sum(string $attribute, string $alias = ''): static
    {
        return new static(Method::Sum, $attribute, $alias !== '' ? [$alias] : []);
    }

    public static function avg(string $attribute, string $alias = ''): static
    {
        return new static(Method::Avg, $attribute, $alias !== '' ? [$alias] : []);
    }

    public static function min(string $attribute, string $alias = ''): static
    {
        return new static(Method::Min, $attribute, $alias !== '' ? [$alias] : []);
    }

    public static function max(string $attribute, string $alias = ''): static
    {
        return new static(Method::Max, $attribute, $alias !== '' ? [$alias] : []);
    }

    public static function stddev(string $attribute, string $alias = ''): static
    {
        return new static(Method::Stddev, $attribute, $alias !== '' ? [$alias] : []);
    }

    public static function stddevPop(string $attribute, string $alias = ''): static
    {
        return new static(Method::StddevPop, $attribute, $alias !== '' ? [$alias] : []);
    }

    public static function stddevSamp(string $attribute, string $alias = ''): static
    {
        return new static(Method::StddevSamp, $attribute, $alias !== '' ? [$alias] : []);
    }

    public static function variance(string $attribute, string $alias = ''): static
    {
        return new static(Method::Variance, $attribute, $alias !== '' ? [$alias] : []);
    }

    public static function varPop(string $attribute, string $alias = ''): static
    {
        return new static(Method::VarPop, $attribute, $alias !== '' ? [$alias] : []);
    }

    public static function varSamp(string $attribute, string $alias = ''): static
    {
        return new static(Method::VarSamp, $attribute, $alias !== '' ? [$alias] : []);
    }

    public static function bitAnd(string $attribute, string $alias = ''): static
    {
        return new static(Method::BitAnd, $attribute, $alias !== '' ? [$alias] : []);
    }

    public static function bitOr(string $attribute, string $alias = ''): static
    {
        return new static(Method::BitOr, $attribute, $alias !== '' ? [$alias] : []);
    }

    public static function bitXor(string $attribute, string $alias = ''): static
    {
        return new static(Method::BitXor, $attribute, $alias !== '' ? [$alias] : []);
    }

    /**
     * @param  array<string>  $attributes
     */
    public static function groupBy(array $attributes): static
    {
        return new static(Method::GroupBy, '', $attributes);
    }

    /**
     * Allowed bucket sizes for `groupByTimeBucket`.
     *
     * Kept narrow on purpose: each bucket maps to a single ClickHouse
     * `toStartOf*` function (see `Builder\ClickHouse::compileGroupByTimeBucket`)
     * so the set is closed and changes are explicit.
     *
     * @var list<string>
     */
    public const array GROUP_BY_TIME_BUCKET_INTERVALS = ['1m', '5m', '15m', '1h', '1d', '1w', '1M'];

    /**
     * Helper method to create Query with groupByTimeBucket method.
     *
     * Buckets `$attribute` into fixed-width windows of size `$interval` and
     * groups by the bucket. Compilation is dialect-specific; see
     * `Builder\ClickHouse::compileGroupByTimeBucket`.
     */
    public static function groupByTimeBucket(string $attribute, string $interval): static
    {
        if (! \in_array($interval, self::GROUP_BY_TIME_BUCKET_INTERVALS, true)) {
            throw new ValidationException(
                'Invalid groupByTimeBucket interval: ' . $interval
                . '. Allowed: ' . \implode(', ', self::GROUP_BY_TIME_BUCKET_INTERVALS)
            );
        }

        return new static(Method::GroupByTimeBucket, $attribute, [$interval]);
    }

    /**
     * @param  array<Query>  $queries
     */
    public static function having(array $queries): static
    {
        return new static(Method::Having, '', $queries);
    }

    public static function distinct(): static
    {
        return new static(Method::Distinct);
    }

    // Join factory methods

    /**
     * Column-to-column join ON condition.
     */
    public static function on(string $left, string $right, string $operator = '='): static
    {
        return new static(Method::On, '', [$left, $operator, $right]);
    }

    /**
     * @param  string|list<Query|string>  $leftOrAliasOrOn
     * @param  string|list<Query|string>  $rightOrOn
     */
    public static function join(string $table, string|array $leftOrAliasOrOn, string|array $rightOrOn = '', string $operator = '=', string $alias = ''): static
    {
        return self::createJoin(Method::Join, $table, $leftOrAliasOrOn, $rightOrOn, $operator, $alias);
    }

    /**
     * @param  string|list<Query|string>  $leftOrAliasOrOn
     * @param  string|list<Query|string>  $rightOrOn
     */
    public static function leftJoin(string $table, string|array $leftOrAliasOrOn, string|array $rightOrOn = '', string $operator = '=', string $alias = ''): static
    {
        return self::createJoin(Method::LeftJoin, $table, $leftOrAliasOrOn, $rightOrOn, $operator, $alias);
    }

    /**
     * @param  string|list<Query|string>  $leftOrAliasOrOn
     * @param  string|list<Query|string>  $rightOrOn
     */
    public static function rightJoin(string $table, string|array $leftOrAliasOrOn, string|array $rightOrOn = '', string $operator = '=', string $alias = ''): static
    {
        return self::createJoin(Method::RightJoin, $table, $leftOrAliasOrOn, $rightOrOn, $operator, $alias);
    }

    public static function crossJoin(string $table, string $alias = ''): static
    {
        return new static(Method::CrossJoin, $table, $alias !== '' ? [$alias] : []);
    }

    /**
     * @param  string|list<Query|string>  $leftOrAliasOrOn
     * @param  string|list<Query|string>  $rightOrOn
     */
    public static function fullOuterJoin(string $table, string|array $leftOrAliasOrOn, string|array $rightOrOn = '', string $operator = '=', string $alias = ''): static
    {
        return self::createJoin(Method::FullOuterJoin, $table, $leftOrAliasOrOn, $rightOrOn, $operator, $alias);
    }

    public static function naturalJoin(string $table, string $alias = ''): static
    {
        return new static(Method::NaturalJoin, $table, $alias !== '' ? [$alias] : []);
    }

    /**
     * @param  string|list<Query|string>  $leftOrAliasOrOn
     * @param  string|list<Query|string>  $rightOrOn
     */
    private static function createJoin(Method $method, string $table, string|array $leftOrAliasOrOn, string|array $rightOrOn, string $operator, string $alias): static
    {
        if (\is_array($leftOrAliasOrOn)) {
            return self::createNestedJoin($method, $table, '', $leftOrAliasOrOn);
        }

        if (\is_array($rightOrOn)) {
            return self::createNestedJoin($method, $table, $leftOrAliasOrOn, $rightOrOn);
        }

        $values = [$leftOrAliasOrOn, $operator, $rightOrOn];
        if ($alias !== '') {
            $values[] = $alias;
        }

        return new static($method, $table, $values);
    }

    /**
     * @param  array<mixed>  $on
     */
    private static function createNestedJoin(Method $method, string $table, string $alias, array $on): static
    {
        if ($on === []) {
            throw new ValidationException('Join ON requires at least one condition');
        }

        $values = [];
        if ($alias !== '') {
            $values[] = $alias;
        }

        foreach ($on as $query) {
            if (\is_string($query)) {
                $query = static::parse($query);
            }
            if (! $query instanceof self) {
                throw new ValidationException('Join ON conditions must be Query objects');
            }
            $values[] = $query;
        }

        return new static($method, $table, $values);
    }

    // Union factory methods

    /**
     * @param  array<Query>  $queries
     */
    public static function union(array $queries): static
    {
        return new static(Method::Union, '', $queries);
    }

    /**
     * @param  array<Query>  $queries
     */
    public static function unionAll(array $queries): static
    {
        return new static(Method::UnionAll, '', $queries);
    }

    // JSON factory methods

    public static function jsonContains(string $attribute, mixed $value): static
    {
        return new static(Method::JsonContains, $attribute, [$value]);
    }

    public static function jsonNotContains(string $attribute, mixed $value): static
    {
        return new static(Method::JsonNotContains, $attribute, [$value]);
    }

    /**
     * @param  array<mixed>  $values
     */
    public static function jsonOverlaps(string $attribute, array $values): static
    {
        return new static(Method::JsonOverlaps, $attribute, [$values]);
    }

    public static function jsonPath(string $attribute, string $path, string $operator, mixed $value): static
    {
        return new static(Method::JsonPath, $attribute, [$path, $operator, $value]);
    }

    // Spatial predicate extras

    /**
     * @param  array<mixed>  $values
     */
    public static function covers(string $attribute, array $values): static
    {
        return new static(Method::Covers, $attribute, [$values]);
    }

    /**
     * @param  array<mixed>  $values
     */
    public static function notCovers(string $attribute, array $values): static
    {
        return new static(Method::NotCovers, $attribute, [$values]);
    }

    /**
     * @param  array<mixed>  $values
     */
    public static function spatialEquals(string $attribute, array $values): static
    {
        return new static(Method::SpatialEquals, $attribute, [$values]);
    }

    /**
     * @param  array<mixed>  $values
     */
    public static function notSpatialEquals(string $attribute, array $values): static
    {
        return new static(Method::NotSpatialEquals, $attribute, [$values]);
    }

    // Raw factory method

    /**
     * @param  array<mixed>  $bindings
     */
    public static function raw(string $sql, array $bindings = []): static
    {
        return new static(Method::Raw, $sql, $bindings);
    }

    // Convenience: page

    /**
     * Returns an array of limit and offset queries for page-based pagination
     *
     * @return array{0: static, 1: static}
     */
    public static function page(int $page, int $perPage = 25): array
    {
        if ($page < 1) {
            throw new ValidationException('Page must be >= 1, got ' . $page);
        }
        if ($perPage < 1) {
            throw new ValidationException('Per page must be >= 1, got ' . $perPage);
        }

        return [
            static::limit($perPage),
            static::offset(($page - 1) * $perPage),
        ];
    }

    // Static helpers

    /**
     * Merge two query arrays. For limit/offset/cursor, values from $queriesB override $queriesA.
     *
     * @param  array<static>  $queriesA
     * @param  array<static>  $queriesB
     * @return array<static>
     */
    public static function merge(array $queriesA, array $queriesB): array
    {
        $singularTypes = [
            Method::Limit,
            Method::Offset,
            Method::CursorAfter,
            Method::CursorBefore,
        ];

        $result = $queriesA;

        foreach ($queriesB as $queryB) {
            $method = $queryB->getMethod();

            if (\in_array($method, $singularTypes, true)) {
                // Remove existing queries of the same type from result
                $result = \array_values(\array_filter(
                    $result,
                    fn (Query $q): bool => $q->getMethod() !== $method
                ));
            }

            $result[] = $queryB;
        }

        return $result;
    }

    /**
     * Returns queries in A that are not in B (compared by toArray())
     *
     * @param  array<static>  $queriesA
     * @param  array<static>  $queriesB
     * @return array<static>
     */
    public static function diff(array $queriesA, array $queriesB): array
    {
        $bArrays = \array_map(fn (Query $q): array => $q->toArray(), $queriesB);

        $result = [];
        foreach ($queriesA as $queryA) {
            $aArray = $queryA->toArray();
            if (! array_any($bArrays, fn (array $b): bool => $aArray === $b)) {
                $result[] = $queryA;
            }
        }

        return $result;
    }

    /**
     * Validate queries against allowed attributes
     *
     * @param  array<static>  $queries
     * @param  array<string>  $allowedAttributes
     * @return array<string>  Error messages
     */
    public static function validate(array $queries, array $allowedAttributes): array
    {
        $errors = [];
        $skipTypes = [
            Method::Limit,
            Method::Offset,
            Method::CursorAfter,
            Method::CursorBefore,
            Method::OrderRandom,
            Method::Distinct,
            Method::Select,
            Method::Exists,
            Method::NotExists,
        ];

        foreach ($queries as $query) {
            $method = $query->getMethod();

            // Recursively validate nested queries
            if ($method->isNested()) {
                /** @var array<static> $nested */
                $nested = $query->getValues();
                \array_push($errors, ...static::validate($nested, $allowedAttributes));

                continue;
            }

            if (\in_array($method, $skipTypes, true)) {
                continue;
            }

            // GROUP_BY stores attributes in values
            if ($method === Method::GroupBy) {
                /** @var array<string> $columns */
                $columns = $query->getValues();
                foreach ($columns as $col) {
                    if (! \in_array($col, $allowedAttributes, true)) {
                        $errors[] = "Invalid attribute \"{$col}\" used in {$method->value}";
                    }
                }

                continue;
            }

            $attribute = $query->getAttribute();

            if ($attribute === '' || $attribute === '*') {
                continue;
            }

            if (! \in_array($attribute, $allowedAttributes, true)) {
                $errors[] = "Invalid attribute \"{$attribute}\" used in {$method->value}";
            }
        }

        return $errors;
    }
}
