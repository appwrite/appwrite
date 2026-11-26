<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

namespace Utopia\Query;

use Utopia\Query\Exception as QueryException;

/** @phpstan-consistent-constructor */
class NativeQuery
{
    public const DEFAULT_ALIAS = 'main';
    protected \Utopia\Query\Method $method;
    protected string $attribute = '';
    protected string $attributeType = '';
    protected bool $onArray = false;
    /**
     * @var array<mixed>
     */
    protected array $values = [];
    protected string $alias = '';
    protected bool $aliasDefaulted = false;
    public function getMethod(): \Utopia\Query\Method
    {
    }
    public function getAttribute(): string
    {
    }
    /**
     * @return array<mixed>
     */
    public function getValues(): array
    {
    }
    public function getValue(mixed $default = null): mixed
    {
    }
    public function isNestedJoin(): bool
    {
    }
    /**
     * The name a join or an aggregate is exposed under, or '' when it has none.
     */
    public function getAlias(): string
    {
    }
    /**
     * Whether a join is read under the name of the collection it joins, as a join given no alias
     * is. It is decided when the query is built, so it holds after the collection is renamed.
     */
    public function isAliasDefaulted(): bool
    {
    }
    /**
     * @return list<self>
     */
    public function getJoinOnQueries(): array
    {
    }
    /**
     * Sets method
     */
    public function setMethod(\Utopia\Query\Method|string $method): static
    {
    }
    /**
     * Sets attribute
     */
    public function setAttribute(string $attribute): static
    {
    }
    /**
     * Sets values
     *
     * @param  array<mixed>  $values
     */
    public function setValues(array $values): static
    {
    }
    /**
     * Sets value
     */
    public function setValue(mixed $value): static
    {
    }
    /**
     * Check if method is supported
     */
    public static function isMethod(string $value): bool
    {
    }
    /**
     * Check if method is a spatial-only query method
     */
    public function isSpatialQuery(): bool
    {
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
    }
    /**
     * Compute a shape-only fingerprint of an array of queries.
     *
     * The fingerprint captures the structure of the queries — method and
     * attribute — without values. Two query sets with the same shape but
     * different parameter values produce the same fingerprint, which is
     * useful for pattern-based counting and slow-query grouping.
     *
     * Logical queries (`and`, `or`, `elemMatch`, `relationship`) contribute their inner
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
    }
    /**
     * Canonical shape string for this Query — values excluded.
     *
     * Non-logical queries produce `method:attribute`. Logical queries
     * (`and`, `or`, `elemMatch`, `relationship`) produce `method:attribute(child1|child2|…)`
     * with children sorted so child order does not affect the shape.
     *
     * Implemented iteratively: walks the tree into a preorder list via a
     * stack, then processes the reversed list so each node's children are
     * always resolved before the node itself.
     */
    public function shape(): string
    {
    }
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
    }
    /**
     * Compile this query using the given compiler
     */
    public function compile(\Utopia\Query\Compiler $compiler): string
    {
    }
    /**
     * @throws QueryException
     */
    public function toString(): string
    {
    }
    /**
     * Helper method to create Query with notEqual method
     *
     * @param  string|int|float|bool|null|array<mixed,mixed>  $value
     */
    public static function notEqual(string $attribute, string|int|float|bool|array|null $value): static
    {
    }
    /**
     * Helper method to create Query with notExists method
     *
     * @param  string|int|float|bool|array<mixed,mixed>  $attribute
     */
    public static function notExists(string|int|float|bool|array $attribute): static
    {
    }
    /**
     * Helper method to create Query with groupByTimeBucket method.
     *
     * Buckets `$attribute` into fixed-width windows of size `$interval` and
     * groups by the bucket. Compilation is dialect-specific; see
     * `Builder\ClickHouse::compileGroupByTimeBucket`.
     */
    public static function groupByTimeBucket(string $attribute, string $interval): static
    {
    }
    // Join factory methods
    /**
     * Column-to-column join ON condition.
     */
    public static function on(string $left, string $right, string $operator = '='): static
    {
    }
    /**
     * @param  string  $alias  The name the join is read under; '' reads it under $collection
     * @param  list<Query>  $on  on() conditions and filters
     */
    public static function join(string $collection, string $alias, array $on): static
    {
    }
    /**
     * @param  string  $alias  The name the join is read under; '' reads it under $collection
     * @param  list<Query>  $on  on() conditions and filters
     */
    public static function leftJoin(string $collection, string $alias, array $on): static
    {
    }
    /**
     * @param  string  $alias  The name the join is read under; '' reads it under $collection
     * @param  list<Query>  $on  on() conditions and filters
     */
    public static function rightJoin(string $collection, string $alias, array $on): static
    {
    }
    /**
     * @param  string  $alias  The name the join is read under; '' reads it under $collection
     * @param  list<Query>  $on  on() conditions and filters
     */
    public static function fullOuterJoin(string $collection, string $alias, array $on): static
    {
    }
    /**
     * @param  string  $alias  The name the join is read under; '' reads it under $collection
     */
    public static function crossJoin(string $collection, string $alias): static
    {
    }
    /**
     * @param  string  $alias  The name the join is read under; '' reads it under $collection
     */
    public static function naturalJoin(string $collection, string $alias): static
    {
    }
    // Convenience: page
    /**
     * Returns an array of limit and offset queries for page-based pagination
     *
     * @return array{0: static, 1: static}
     */
    public static function page(int $page, int $perPage = 25): array
    {
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
    }
    /**
     * @param  array<static>  $queries
     * @return array<static>
     */
    public static function getCursorQueries(array $queries, bool $clone = true): array
    {
    }
    /**
     * Iterates through queries and groups them by type
     *
     * @param  array<mixed>  $queries
     */
    public static function groupByType(array $queries): \Utopia\Query\Builder\ParsedQuery
    {
    }
    /**
     * Is this query able to contain other queries
     */
    public function isNested(): bool
    {
    }
    public function onArray(): bool
    {
    }
    public function setOnArray(bool $bool): void
    {
    }
    public function setAttributeType(string $type): void
    {
    }
    public function getAttributeType(): string
    {
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
    }
    /**
     * Helper method to create Query with equal method
     *
     * @param  array<string|int|float|bool|null|array<mixed,mixed>>  $values
     */
    public static function equal(string $attribute, array $values): static
    {
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
    }
    /**
     * Helper method to create Query for string substring matching.
     * Compiles to LIKE '%value%' for each given value.
     *
     * @param  array<mixed>  $values
     */
    public static function containsString(string $attribute, array $values): static
    {
    }
    /**
     * Helper method to create Query with containsAny method.
     * For array and relationship attributes, matches documents where the attribute contains ANY of the given values.
     *
     * @param  array<mixed>  $values
     */
    public static function containsAny(string $attribute, array $values): static
    {
    }
    /**
     * Helper method to create Query with notContains method
     *
     * @param  array<mixed>  $values
     */
    public static function notContains(string $attribute, array $values): static
    {
    }
    /**
     * @param  array<mixed>  $values
     */
    public static function containsAll(string $attribute, array $values): static
    {
    }
    /**
     * Helper method to create Query with lessThan method
     */
    public static function lessThan(string $attribute, string|int|float|bool $value): static
    {
    }
    /**
     * Helper method to create Query with lessThanEqual method
     */
    public static function lessThanEqual(string $attribute, string|int|float|bool $value): static
    {
    }
    /**
     * Helper method to create Query with greaterThan method
     */
    public static function greaterThan(string $attribute, string|int|float|bool $value): static
    {
    }
    /**
     * Helper method to create Query with greaterThanEqual method
     */
    public static function greaterThanEqual(string $attribute, string|int|float|bool $value): static
    {
    }
    /**
     * Helper method to create Query with between method
     */
    public static function between(string $attribute, string|int|float|bool $start, string|int|float|bool $end): static
    {
    }
    /**
     * Helper method to create Query with notBetween method
     */
    public static function notBetween(string $attribute, string|int|float|bool $start, string|int|float|bool $end): static
    {
    }
    /**
     * Helper method to create Query with search method
     */
    public static function search(string $attribute, string $value): static
    {
    }
    /**
     * Helper method to create Query with notSearch method
     */
    public static function notSearch(string $attribute, string $value): static
    {
    }
    public static function startsWith(string $attribute, string $value): static
    {
    }
    public static function notStartsWith(string $attribute, string $value): static
    {
    }
    public static function endsWith(string $attribute, string $value): static
    {
    }
    public static function notEndsWith(string $attribute, string $value): static
    {
    }
    /**
     * Helper method to create Query with regex method
     */
    public static function regex(string $attribute, string $pattern): static
    {
    }
    /**
     * Helper method to create Query with isNull method
     */
    public static function isNull(string $attribute): static
    {
    }
    /**
     * Helper method to create Query with isNotNull method
     */
    public static function isNotNull(string $attribute): static
    {
    }
    /**
     * Helper method to create Query for documents created before a specific date
     */
    public static function createdBefore(string $value): static
    {
    }
    /**
     * Helper method to create Query for documents created after a specific date
     */
    public static function createdAfter(string $value): static
    {
    }
    /**
     * Helper method to create Query for documents updated before a specific date
     */
    public static function updatedBefore(string $value): static
    {
    }
    /**
     * Helper method to create Query for documents updated after a specific date
     */
    public static function updatedAfter(string $value): static
    {
    }
    /**
     * Helper method to create Query for documents created between two dates
     */
    public static function createdBetween(string $start, string $end): static
    {
    }
    /**
     * Helper method to create Query for documents updated between two dates
     */
    public static function updatedBetween(string $start, string $end): static
    {
    }
    /**
     * Helper method to create Query with select method
     *
     * @param  array<string>  $attributes
     */
    public static function select(array $attributes): static
    {
    }
    /**
     * @param  array<Query>  $queries
     */
    public static function or(array $queries): static
    {
    }
    /**
     * @param  array<Query>  $queries
     */
    public static function and(array $queries): static
    {
    }
    /**
     * @param  array<Query>  $queries
     */
    public static function having(array $queries): static
    {
    }
    // Union factory methods
    /**
     * @param  array<Query>  $queries
     */
    public static function union(array $queries): static
    {
    }
    /**
     * @param  array<Query>  $queries
     */
    public static function unionAll(array $queries): static
    {
    }
    /**
     * Helper method to create Query with exists method
     *
     * @param  array<string>  $attributes
     */
    public static function exists(array $attributes): static
    {
    }
    /**
     * @param  array<string>  $attributes
     */
    public static function groupBy(array $attributes): static
    {
    }
    /**
     * @param  array<Query>  $queries
     */
    public static function elemMatch(string $attribute, array $queries): static
    {
    }
    /**
     * Queries that shape the documents populated for a relationship attribute,
     * written against the related collection.
     *
     * @param  array<Query>  $queries
     */
    public static function relationship(string $attribute, array $queries): static
    {
    }
    /**
     * Helper method to create Query with orderRandom method
     */
    public static function orderRandom(): static
    {
    }
    public static function distinct(): static
    {
    }
    /**
     * Helper method to create Query with orderDesc method
     */
    public static function orderDesc(string $attribute = '', ?\Utopia\Query\NullsPosition $nulls = null): static
    {
    }
    /**
     * Helper method to create Query with orderAsc method
     */
    public static function orderAsc(string $attribute = '', ?\Utopia\Query\NullsPosition $nulls = null): static
    {
    }
    /**
     * Helper method to create Query with limit method
     */
    public static function limit(int $value): static
    {
    }
    /**
     * Helper method to create Query with offset method
     */
    public static function offset(int $value): static
    {
    }
    /**
     * Page after a row: an associative array of its columns, or an object
     * such as a document.
     *
     * @param  array<mixed>|object  $value
     */
    public static function cursorAfter(array|object $value): static
    {
    }
    /**
     * Page before a row: an associative array of its columns, or an object
     * such as a document.
     *
     * @param  array<mixed>|object  $value
     */
    public static function cursorBefore(array|object $value): static
    {
    }
    // Spatial query methods
    /**
     * Helper method to create Query with distanceEqual method
     *
     * @param  array<mixed>  $values
     */
    public static function distanceEqual(string $attribute, array $values, int|float $distance, bool $meters = false): static
    {
    }
    /**
     * Helper method to create Query with distanceNotEqual method
     *
     * @param  array<mixed>  $values
     */
    public static function distanceNotEqual(string $attribute, array $values, int|float $distance, bool $meters = false): static
    {
    }
    /**
     * Helper method to create Query with distanceGreaterThan method
     *
     * @param  array<mixed>  $values
     */
    public static function distanceGreaterThan(string $attribute, array $values, int|float $distance, bool $meters = false): static
    {
    }
    /**
     * Helper method to create Query with distanceLessThan method
     *
     * @param  array<mixed>  $values
     */
    public static function distanceLessThan(string $attribute, array $values, int|float $distance, bool $meters = false): static
    {
    }
    /**
     * Helper method to create Query with intersects method
     *
     * @param  array<mixed>  $values
     */
    public static function intersects(string $attribute, array $values): static
    {
    }
    /**
     * Helper method to create Query with notIntersects method
     *
     * @param  array<mixed>  $values
     */
    public static function notIntersects(string $attribute, array $values): static
    {
    }
    /**
     * Helper method to create Query with crosses method
     *
     * @param  array<mixed>  $values
     */
    public static function crosses(string $attribute, array $values): static
    {
    }
    /**
     * Helper method to create Query with notCrosses method
     *
     * @param  array<mixed>  $values
     */
    public static function notCrosses(string $attribute, array $values): static
    {
    }
    /**
     * Helper method to create Query with overlaps method
     *
     * @param  array<mixed>  $values
     */
    public static function overlaps(string $attribute, array $values): static
    {
    }
    /**
     * Helper method to create Query with notOverlaps method
     *
     * @param  array<mixed>  $values
     */
    public static function notOverlaps(string $attribute, array $values): static
    {
    }
    /**
     * Helper method to create Query with touches method
     *
     * @param  array<mixed>  $values
     */
    public static function touches(string $attribute, array $values): static
    {
    }
    /**
     * Helper method to create Query with notTouches method
     *
     * @param  array<mixed>  $values
     */
    public static function notTouches(string $attribute, array $values): static
    {
    }
    /**
     * Helper method to create Query with vectorDot method
     *
     * @param  array<float>  $vector
     */
    public static function vectorDot(string $attribute, array $vector): static
    {
    }
    /**
     * Helper method to create Query with vectorCosine method
     *
     * @param  array<float>  $vector
     */
    public static function vectorCosine(string $attribute, array $vector): static
    {
    }
    /**
     * Helper method to create Query with vectorEuclidean method
     *
     * @param  array<float>  $vector
     */
    public static function vectorEuclidean(string $attribute, array $vector): static
    {
    }
    // Spatial predicate extras
    /**
     * @param  array<mixed>  $values
     */
    public static function covers(string $attribute, array $values): static
    {
    }
    /**
     * @param  array<mixed>  $values
     */
    public static function notCovers(string $attribute, array $values): static
    {
    }
    /**
     * @param  array<mixed>  $values
     */
    public static function spatialEquals(string $attribute, array $values): static
    {
    }
    /**
     * @param  array<mixed>  $values
     */
    public static function notSpatialEquals(string $attribute, array $values): static
    {
    }
    /**
     * @param  array<mixed>  $values
     */
    public static function jsonOverlaps(string $attribute, array $values): static
    {
    }
    // Aggregation factory methods
    public static function count(string $attribute = '*', string $alias = ''): static
    {
    }
    public static function countDistinct(string $attribute, string $alias = ''): static
    {
    }
    public static function sum(string $attribute, string $alias = ''): static
    {
    }
    public static function avg(string $attribute, string $alias = ''): static
    {
    }
    public static function min(string $attribute, string $alias = ''): static
    {
    }
    public static function max(string $attribute, string $alias = ''): static
    {
    }
    public static function stddev(string $attribute, string $alias = ''): static
    {
    }
    public static function stddevPop(string $attribute, string $alias = ''): static
    {
    }
    public static function stddevSamp(string $attribute, string $alias = ''): static
    {
    }
    public static function variance(string $attribute, string $alias = ''): static
    {
    }
    public static function varPop(string $attribute, string $alias = ''): static
    {
    }
    public static function varSamp(string $attribute, string $alias = ''): static
    {
    }
    public static function bitAnd(string $attribute, string $alias = ''): static
    {
    }
    public static function bitOr(string $attribute, string $alias = ''): static
    {
    }
    public static function bitXor(string $attribute, string $alias = ''): static
    {
    }
    // JSON factory methods
    public static function jsonContains(string $attribute, mixed $value): static
    {
    }
    public static function jsonNotContains(string $attribute, mixed $value): static
    {
    }
    public static function jsonPath(string $attribute, string $path, string $operator, mixed $value): static
    {
    }
    // Raw factory method
    /**
     * @param  array<mixed>  $bindings
     */
    public static function raw(string $sql, array $bindings = []): static
    {
    }
    /**
     * Construct a new query object
     *
     * @param  array<mixed>  $values
     */
    public function __construct(\Utopia\Query\Method|string $method, string $attribute = '', array $values = [], string $alias = '')
    {
    }
    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
    }
    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
    }
}
