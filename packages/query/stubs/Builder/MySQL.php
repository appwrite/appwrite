<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

namespace Utopia\Query\Builder;

use Utopia\Query\AST\Expression;
use Utopia\Query\AST\Expression\Between;
use Utopia\Query\AST\Expression\In;
use Utopia\Query\AST\Literal;
use Utopia\Query\AST\Raw;
use Utopia\Query\AST\Reference\Column;
use Utopia\Query\AST\Reference\Table;
use Utopia\Query\AST\Statement\Select;
use Utopia\Query\Builder;
use Utopia\Query\Builder\Feature\Hints;
use Utopia\Query\Exception\ValidationException;
use Utopia\Query\Hook;
use Utopia\Query\Hook\Attribute;
use Utopia\Query\Hook\Filter;
use Utopia\Query\Hook\Join\Placement;
use Utopia\Query\Method;
use Utopia\Query\Query;

class MySQL extends \Utopia\Query\Builder\SQL implements \Utopia\Query\Builder\Feature\Json, \Utopia\Query\Builder\Feature\Hints, \Utopia\Query\Builder\Feature\ConditionalAggregates, \Utopia\Query\Builder\Feature\LateralJoins, \Utopia\Query\Builder\Feature\StringAggregates, \Utopia\Query\Builder\Feature\Rollup, \Utopia\Query\Builder\Feature\Spatial, \Utopia\Query\Builder\Feature\FullTextSearch, \Utopia\Query\Builder\Feature\NegatedFullTextSearch, \Utopia\Query\Builder\Feature\Upsert, \Utopia\Query\Builder\Feature\UpsertSelect, \Utopia\Query\Builder\Feature\InsertOrIgnore
{
    /**
     * Parameter bindings produced during compilation. Each entry carries its
     * value plus the raw column name supplied at bind time (when known).
     * `Statement::$bindings` stays `list<mixed>` for public consumption — the
     * `Binding` wrapper is an internal capture so dialects with typed named
     * placeholders can look up a registered type without a parallel array.
     *
     * @var list<Binding>
     */
    protected array $bindings = [];
    /**
     * @var array<Query>
     */
    protected array $pendingQueries = [];
    /**
     * Per-build memo of resolveAttribute() results keyed by raw name.
     *
     * Populated on first resolution, cleared at the top of build() and on
     * reset(). The same attribute is resolved many times per build (SELECT,
     * WHERE, GROUP BY, ORDER BY, JOIN ON, UPDATE SET) and each resolution
     * iterates every registered attribute hook.
     *
     * @var array<string, string>
     */
    protected array $resolvedAttributeCache = [];
    #[\Override]
    public function from(string $table = '', string $alias = ''): static
    {
    }
    public function fromNone(): static
    {
    }
    /**
     * @param  string|array<string>  $columns
     * @param  list<mixed>  $bindings
     */
    #[\Override]
    public function select(string|array $columns, array $bindings = []): static
    {
    }
    #[\Override]
    public function distinct(): static
    {
    }
    /**
     * @param  array<Query>  $queries
     */
    #[\Override]
    public function filter(array $queries): static
    {
    }
    /**
     * @param  array<Query>  $queries
     */
    #[\Override]
    public function queries(array $queries): static
    {
    }
    /**
     * @param  array<Query>  $queries
     */
    #[\Override]
    public function having(array $queries): static
    {
    }
    #[\Override]
    public function sortAsc(string $attribute, ?\Utopia\Query\NullsPosition $nulls = null): static
    {
    }
    #[\Override]
    public function sortDesc(string $attribute, ?\Utopia\Query\NullsPosition $nulls = null): static
    {
    }
    #[\Override]
    public function sortRandom(): static
    {
    }
    #[\Override]
    public function limit(int $value): static
    {
    }
    #[\Override]
    public function offset(int $value): static
    {
    }
    #[\Override]
    public function fetch(int $count, bool $withTies = false): static
    {
    }
    #[\Override]
    public function page(int $page, int $perPage = 25): static
    {
    }
    /**
     * @param  array<mixed>|object  $value
     */
    #[\Override]
    public function cursorAfter(array|object $value): static
    {
    }
    /**
     * @param  array<mixed>|object  $value
     */
    #[\Override]
    public function cursorBefore(array|object $value): static
    {
    }
    #[\Override]
    public function count(string $attribute = '*', string $alias = ''): static
    {
    }
    #[\Override]
    public function countDistinct(string $attribute, string $alias = ''): static
    {
    }
    #[\Override]
    public function sum(string $attribute, string $alias = ''): static
    {
    }
    #[\Override]
    public function avg(string $attribute, string $alias = ''): static
    {
    }
    #[\Override]
    public function min(string $attribute, string $alias = ''): static
    {
    }
    #[\Override]
    public function max(string $attribute, string $alias = ''): static
    {
    }
    /**
     * @param  array<string>  $columns
     */
    #[\Override]
    public function groupBy(array $columns): static
    {
    }
    #[\Override]
    public function groupByTimeBucket(string $attribute, string $interval): static
    {
    }
    #[\Override]
    public function join(string $table, string $left, string $right, string $operator = '=', string $alias = ''): static
    {
    }
    #[\Override]
    public function leftJoin(string $table, string $left, string $right, string $operator = '=', string $alias = ''): static
    {
    }
    #[\Override]
    public function rightJoin(string $table, string $left, string $right, string $operator = '=', string $alias = ''): static
    {
    }
    #[\Override]
    public function into(string $table): static
    {
    }
    /**
     * Set an alias for the INSERT target table (e.g. INSERT INTO table AS alias).
     * Used by PostgreSQL ON CONFLICT to reference the existing row.
     */
    public function insertAs(string $alias): static
    {
    }
    /**
     * @param  array<string, mixed>  $row
     */
    #[\Override]
    public function set(array $row): static
    {
    }
    /**
     * @param  string[]  $keys
     * @param  string[]  $updateColumns
     */
    #[\Override]
    public function onConflict(array $keys, array $updateColumns): static
    {
    }
    #[\Override]
    public function reset(): static
    {
    }
    /**
     * @return list<mixed>
     */
    #[\Override]
    public function getBindings(): array
    {
    }
    #[\Override]
    public function toRawSql(): string
    {
    }
    #[\Override]
    public function insert(): \Utopia\Query\Builder\Statement
    {
    }
    #[\Override]
    public function insertDefaultValues(): \Utopia\Query\Builder\Statement
    {
    }
    #[\Override]
    public function update(): \Utopia\Query\Builder\Statement
    {
    }
    #[\Override]
    public function delete(): \Utopia\Query\Builder\Statement
    {
    }
    public function fromSub(\Utopia\Query\Builder $subquery, string $alias): static
    {
    }
    public function selectSub(\Utopia\Query\Builder $subquery, string $alias): static
    {
    }
    public function filterWhereIn(string $column, \Utopia\Query\Builder $subquery): static
    {
    }
    public function filterWhereNotIn(string $column, \Utopia\Query\Builder $subquery): static
    {
    }
    public function filterExists(\Utopia\Query\Builder $subquery): static
    {
    }
    public function filterNotExists(\Utopia\Query\Builder $subquery): static
    {
    }
    #[\Override]
    public function union(\Utopia\Query\Builder $other): static
    {
    }
    #[\Override]
    public function unionAll(\Utopia\Query\Builder $other): static
    {
    }
    #[\Override]
    public function intersect(\Utopia\Query\Builder $other): static
    {
    }
    #[\Override]
    public function intersectAll(\Utopia\Query\Builder $other): static
    {
    }
    #[\Override]
    public function except(\Utopia\Query\Builder $other): static
    {
    }
    #[\Override]
    public function exceptAll(\Utopia\Query\Builder $other): static
    {
    }
    /**
     * @param  list<string>  $columns
     */
    #[\Override]
    public function with(string $name, \Utopia\Query\Builder $query, array $columns = []): static
    {
    }
    /**
     * @param  list<string>  $columns
     */
    #[\Override]
    public function withRecursive(string $name, \Utopia\Query\Builder $query, array $columns = []): static
    {
    }
    /**
     * @param  list<string>  $columns
     */
    #[\Override]
    public function withRecursiveSeedStep(string $name, \Utopia\Query\Builder $seed, \Utopia\Query\Builder $step, array $columns = []): static
    {
    }
    /**
     * @param  list<string>  $columns
     */
    #[\Override]
    public function fromSelect(array $columns, \Utopia\Query\Builder $source): static
    {
    }
    #[\Override]
    public function insertSelect(): \Utopia\Query\Builder\Statement
    {
    }
    /**
     * @param  \Closure(JoinBuilder): void  $callback
     */
    #[\Override]
    public function joinWhere(string $table, \Closure $callback, \Utopia\Query\Builder\JoinType $type = \Utopia\Query\Builder\JoinType::Inner, string $alias = ''): static
    {
    }
    #[\Override]
    public function addHook(\Utopia\Query\Hook $hook): static
    {
    }
    #[\Override]
    public function selectWindow(string $function, string $alias, ?array $partitionBy = null, ?array $orderBy = null, ?string $windowName = null, ?\Utopia\Query\Builder\WindowFrame $frame = null): static
    {
    }
    #[\Override]
    public function window(string $name, ?array $partitionBy = null, ?array $orderBy = null, ?\Utopia\Query\Builder\WindowFrame $frame = null): static
    {
    }
    #[\Override]
    public function when(bool $condition, \Closure $callback): static
    {
    }
    public function beforeBuild(\Closure $callback): static
    {
    }
    public function afterBuild(\Closure $callback): static
    {
    }
    /**
     * @param  \Closure(Statement): (array<mixed>|int)  $executor
     */
    public function setExecutor(\Closure $executor): static
    {
    }
    /**
     * @return array<mixed>|int
     */
    public function execute(): array|int
    {
    }
    public function clone(): static
    {
    }
    #[\Override]
    public function build(): \Utopia\Query\Builder\Statement
    {
    }
    #[\Override]
    public function compileFilter(\Utopia\Query\Query $query): string
    {
    }
    #[\Override]
    public function compileOrder(\Utopia\Query\Query $query): string
    {
    }
    #[\Override]
    public function compileLimit(\Utopia\Query\Query $query): string
    {
    }
    #[\Override]
    public function compileOffset(\Utopia\Query\Query $query): string
    {
    }
    #[\Override]
    public function compileSelect(\Utopia\Query\Query $query): string
    {
    }
    #[\Override]
    public function compileCursor(\Utopia\Query\Query $query): string
    {
    }
    #[\Override]
    public function compileAggregate(\Utopia\Query\Query $query): string
    {
    }
    #[\Override]
    public function compileGroupBy(\Utopia\Query\Query $query): string
    {
    }
    #[\Override]
    public function compileJoin(\Utopia\Query\Query $query): string
    {
    }
    /**
     * @param  array<mixed>  $values
     */
    protected function compileIn(string $attribute, array $values, ?string $column = null): string
    {
    }
    /**
     * @param  array<mixed>  $values
     */
    protected function compileNotIn(string $attribute, array $values, ?string $column = null): string
    {
    }
    /**
     * @param  array<mixed>  $values
     */
    #[\Override]
    protected function compileRegex(string $attribute, array $values, ?string $column = null): string
    {
    }
    /**
     * @param  array<mixed>  $values
     */
    protected function compileContains(string $attribute, array $values, ?string $column = null): string
    {
    }
    /**
     * @param  array<mixed>  $values
     */
    protected function compileContainsAll(string $attribute, array $values, ?string $column = null): string
    {
    }
    /**
     * @param  array<mixed>  $values
     */
    protected function compileNotContains(string $attribute, array $values, ?string $column = null): string
    {
    }
    protected function compileJoinWithBuilder(\Utopia\Query\Query $query, \Utopia\Query\Builder\JoinBuilder $joinBuilder): string
    {
    }
    protected function resolveAttribute(string $attribute): string
    {
    }
    protected function resolveAndWrap(string $attribute): string
    {
    }
    protected function quote(string $identifier): string
    {
    }
    protected function getLikeKeyword(): string
    {
    }
    /**
     * Return the binding values in compile order as a plain `list<mixed>`
     * for `Statement::$bindings`. The wrapped `Binding` objects stay
     * internal to the builder.
     *
     * @return list<mixed>
     */
    protected function getBindingValues(): array
    {
    }
    /**
     * @param  array<mixed>  $values
     */
    protected function compileLike(string $attribute, array $values, string $prefix, string $suffix, bool $not, ?string $column = null): string
    {
    }
    /**
     * Append a single parameter binding, capturing its source column at the
     * moment of binding so dialects with typed named placeholders can
     * resolve a registered type without a parallel array. Most call sites
     * pass just the value; the column hint is null for literals (LIMIT,
     * cursor) and for sub-statement bindings that have already been
     * compiled.
     */
    protected function addBinding(mixed $value, ?string $column = null): void
    {
    }
    /**
     * @param  array<mixed>  $values
     */
    #[\Override]
    protected function compileSearchExpression(string $attribute, array $values, bool $not): string
    {
    }
    /**
     * @param  array<mixed>  $values
     */
    #[\Override]
    protected function compileJsonContainsExpression(string $attribute, array $values, bool $not): string
    {
    }
    /**
     * @param  array<mixed>  $values
     */
    #[\Override]
    protected function compileJsonOverlapsExpression(string $attribute, array $values): string
    {
    }
    protected function compileSpatialFilter(\Utopia\Query\Method $method, string $attribute, \Utopia\Query\Query $query): string
    {
    }
    protected function compileArrayFilter(\Utopia\Query\Method $method, string $attribute, \Utopia\Query\Query $query): string
    {
    }
    /**
     * @param  array<mixed>  $geometry  [x, y] for a point, a list of points for a line, a list of rings for a polygon.
     *
     * @throws ValidationException
     */
    protected function geometryToWkt(array $geometry): string
    {
    }
    /**
     * Quote a single identifier without treating dots as qualifier separators.
     *
     * Use when the identifier is known to be atomic — e.g. a column name in a
     * CREATE TABLE definition where the dot is a literal part of the name
     * rather than a `schema.table.column` separator. The canonical case is
     * ClickHouse's nested-array convention (`meta.key Array(String)`) where
     * `meta.key` is a single top-level column whose name contains a dot.
     */
    protected function quoteLiteral(string $identifier): string
    {
    }
    /**
     * @param  list<mixed>  $bindings
     */
    public function selectRaw(string $expression, array $bindings = []): static
    {
    }
    /**
     * Append a raw WHERE fragment with its own bindings.
     *
     * Caller owns the SQL fragment - no column or operator validation is performed.
     * Use this sparingly; prefer `filter()` with typed `Query::*` factories when possible.
     *
     * @param  list<mixed>  $bindings
     */
    public function whereRaw(string $expression, array $bindings = []): static
    {
    }
    /**
     * @param  list<mixed>  $bindings
     */
    public function orderByRaw(string $expression, array $bindings = []): static
    {
    }
    /**
     * @param  list<mixed>  $bindings
     */
    public function groupByRaw(string $expression, array $bindings = []): static
    {
    }
    /**
     * @param  list<mixed>  $bindings
     */
    public function havingRaw(string $expression, array $bindings = []): static
    {
    }
    /**
     * @param  list<mixed>  $bindings
     */
    #[\Override]
    public function setRaw(string $column, string $expression, array $bindings = []): static
    {
    }
    /**
     * @param  list<mixed>  $bindings
     */
    public function conflictSetRaw(string $column, string $expression, array $bindings = []): static
    {
    }
    /**
     * Register a raw expression wrapper for a column in INSERT statements.
     *
     * The expression must contain exactly one `?` placeholder which will receive
     * the column's value from each row. E.g. `ST_GeomFromText(?, 4326)`.
     *
     * @param  list<mixed>  $extraBindings  Additional bindings beyond the column value (e.g. SRID)
     */
    public function insertColumnExpression(string $column, string $expression, array $extraBindings = []): static
    {
    }
    #[\Override]
    public function forUpdate(): static
    {
    }
    #[\Override]
    public function forShare(): static
    {
    }
    public function upsert(): \Utopia\Query\Builder\Statement
    {
    }
    public function upsertSelect(): \Utopia\Query\Builder\Statement
    {
    }
    #[\Override]
    public function insertOrIgnore(): \Utopia\Query\Builder\Statement
    {
    }
    public function updateJoin(string $table, string $left, string $right, string $alias = ''): static
    {
    }
    public function deleteJoin(string $alias, string $table, string $left, string $right): static
    {
    }
    #[\Override]
    public function explain(bool $analyze = false, string $format = ''): \Utopia\Query\Builder\Statement
    {
    }
    #[\Override]
    public function crossJoin(string $table, string $alias = ''): static
    {
    }
    #[\Override]
    public function naturalJoin(string $table, string $alias = ''): static
    {
    }
    #[\Override]
    public function forUpdateSkipLocked(): static
    {
    }
    #[\Override]
    public function forUpdateNoWait(): static
    {
    }
    #[\Override]
    public function forShareSkipLocked(): static
    {
    }
    #[\Override]
    public function forShareNoWait(): static
    {
    }
    #[\Override]
    public function stddev(string $attribute, string $alias = ''): static
    {
    }
    #[\Override]
    public function stddevPop(string $attribute, string $alias = ''): static
    {
    }
    #[\Override]
    public function stddevSamp(string $attribute, string $alias = ''): static
    {
    }
    #[\Override]
    public function variance(string $attribute, string $alias = ''): static
    {
    }
    #[\Override]
    public function varPop(string $attribute, string $alias = ''): static
    {
    }
    #[\Override]
    public function varSamp(string $attribute, string $alias = ''): static
    {
    }
    #[\Override]
    public function bitAnd(string $attribute, string $alias = ''): static
    {
    }
    #[\Override]
    public function bitOr(string $attribute, string $alias = ''): static
    {
    }
    #[\Override]
    public function bitXor(string $attribute, string $alias = ''): static
    {
    }
    #[\Override]
    public function selectCast(string $column, string $type, string $alias = ''): static
    {
    }
    /**
     * Append a column-to-column WHERE predicate (e.g. `users.id = orders.user_id`).
     *
     * Both columns are quoted per dialect. The operator is validated against
     * an allowlist: =, !=, <>, <, >, <=, >=.
     */
    public function whereColumn(string $left, string $operator, string $right): static
    {
    }
    #[\Override]
    public function withRollup(): static
    {
    }
    #[\Override]
    public function hint(string $hint): static
    {
    }
    #[\Override]
    public function countWhen(string $condition, string $alias = '', mixed ...$bindings): static
    {
    }
    #[\Override]
    public function sumWhen(string $column, string $condition, string $alias = '', mixed ...$bindings): static
    {
    }
    #[\Override]
    public function avgWhen(string $column, string $condition, string $alias = '', mixed ...$bindings): static
    {
    }
    #[\Override]
    public function minWhen(string $column, string $condition, string $alias = '', mixed ...$bindings): static
    {
    }
    #[\Override]
    public function maxWhen(string $column, string $condition, string $alias = '', mixed ...$bindings): static
    {
    }
    #[\Override]
    public function jsonArrayAgg(string $column, string $alias = ''): static
    {
    }
    #[\Override]
    public function jsonObjectAgg(string $keyColumn, string $valueColumn, string $alias = ''): static
    {
    }
    public function filterJsonContains(string $attribute, mixed $value): static
    {
    }
    public function filterJsonNotContains(string $attribute, mixed $value): static
    {
    }
    /**
     * @param  array<mixed>  $values
     */
    public function filterJsonOverlaps(string $attribute, array $values): static
    {
    }
    public function filterJsonPath(string $attribute, string $path, string $operator, mixed $value): static
    {
    }
    #[\Override]
    public function setJsonAppend(string $column, array $values): static
    {
    }
    #[\Override]
    public function setJsonPrepend(string $column, array $values): static
    {
    }
    #[\Override]
    public function setJsonInsert(string $column, int $index, mixed $value): static
    {
    }
    #[\Override]
    public function setJsonRemove(string $column, mixed $value): static
    {
    }
    #[\Override]
    public function setJsonIntersect(string $column, array $values): static
    {
    }
    #[\Override]
    public function setJsonDiff(string $column, array $values): static
    {
    }
    #[\Override]
    public function setJsonUnique(string $column): static
    {
    }
    #[\Override]
    public function setJsonPath(string $column, string $path, mixed $value): static
    {
    }
    #[\Override]
    public function filterSearch(string $attribute, string $value): static
    {
    }
    #[\Override]
    public function filterNotSearch(string $attribute, string $value): static
    {
    }
    /**
     * @param  array<float|int>  $point
     */
    #[\Override]
    public function filterDistance(string $attribute, array $point, string $operator, float $distance, bool $meters = false): static
    {
    }
    /**
     * @param  array<mixed>  $geometry
     */
    #[\Override]
    public function filterIntersects(string $attribute, array $geometry): static
    {
    }
    /**
     * @param  array<mixed>  $geometry
     */
    #[\Override]
    public function filterNotIntersects(string $attribute, array $geometry): static
    {
    }
    /**
     * @param  array<mixed>  $geometry
     */
    #[\Override]
    public function filterCrosses(string $attribute, array $geometry): static
    {
    }
    /**
     * @param  array<mixed>  $geometry
     */
    #[\Override]
    public function filterNotCrosses(string $attribute, array $geometry): static
    {
    }
    /**
     * @param  array<mixed>  $geometry
     */
    #[\Override]
    public function filterOverlaps(string $attribute, array $geometry): static
    {
    }
    /**
     * @param  array<mixed>  $geometry
     */
    #[\Override]
    public function filterNotOverlaps(string $attribute, array $geometry): static
    {
    }
    /**
     * @param  array<mixed>  $geometry
     */
    #[\Override]
    public function filterTouches(string $attribute, array $geometry): static
    {
    }
    /**
     * @param  array<mixed>  $geometry
     */
    #[\Override]
    public function filterNotTouches(string $attribute, array $geometry): static
    {
    }
    /**
     * @param  array<mixed>  $geometry
     */
    #[\Override]
    public function filterCovers(string $attribute, array $geometry): static
    {
    }
    /**
     * @param  array<mixed>  $geometry
     */
    #[\Override]
    public function filterNotCovers(string $attribute, array $geometry): static
    {
    }
    /**
     * @param  array<mixed>  $geometry
     */
    #[\Override]
    public function filterSpatialEquals(string $attribute, array $geometry): static
    {
    }
    /**
     * @param  array<mixed>  $geometry
     */
    #[\Override]
    public function filterNotSpatialEquals(string $attribute, array $geometry): static
    {
    }
    #[\Override]
    public function begin(): \Utopia\Query\Builder\Statement
    {
    }
    #[\Override]
    public function commit(): \Utopia\Query\Builder\Statement
    {
    }
    #[\Override]
    public function rollback(): \Utopia\Query\Builder\Statement
    {
    }
    #[\Override]
    public function savepoint(string $name): \Utopia\Query\Builder\Statement
    {
    }
    #[\Override]
    public function releaseSavepoint(string $name): \Utopia\Query\Builder\Statement
    {
    }
    #[\Override]
    public function rollbackToSavepoint(string $name): \Utopia\Query\Builder\Statement
    {
    }
    public function maxExecutionTime(int $ms): static
    {
    }
    #[\Override]
    public function joinLateral(\Utopia\Query\Builder $subquery, string $alias, \Utopia\Query\Builder\JoinType $type = \Utopia\Query\Builder\JoinType::Inner): static
    {
    }
    #[\Override]
    public function leftJoinLateral(\Utopia\Query\Builder $subquery, string $alias): static
    {
    }
    /**
     * @param  list<string>|null  $orderBy
     */
    #[\Override]
    public function groupConcat(string $column, string $separator = ',', string $alias = '', ?array $orderBy = null): static
    {
    }
    public function selectCase(\Utopia\Query\Builder\Case\Expression $case): static
    {
    }
    public function setCase(string $column, \Utopia\Query\Builder\Case\Expression $case): static
    {
    }
    public function toAst(): \Utopia\Query\AST\Statement\Select
    {
    }
    public static function fromAst(\Utopia\Query\AST\Statement\Select $ast): static
    {
    }
    #[\Override]
    protected function compileRandom(): string
    {
    }
    protected function buildTableClause(): string
    {
    }
    /**
     * Hook called after GROUP BY and before HAVING. Override to emit
     * dialect-specific group-by modifiers (e.g. ClickHouse WITH TOTALS).
     */
    protected function buildAfterGroupByClause(): string
    {
    }
    /**
     * Hook called after ORDER BY and before LIMIT. Override to emit
     * dialect-specific clauses that bind between ordering and pagination
     * (e.g. ClickHouse LIMIT BY).
     */
    protected function buildAfterOrderByClause(): string
    {
    }
    /**
     * Hook called at the very end of the SELECT statement (just before any
     * UNION suffix). Override to emit dialect-specific settings fragments
     * (e.g. ClickHouse SETTINGS).
     */
    protected function buildSettingsClause(): string
    {
    }
    /**
     * Wrap a compound-SELECT member for inclusion in a UNION chain. The
     * default wraps each arm in parentheses, matching the shape most
     * dialects expect. Override in dialects whose parsers reject the
     * parenthesised form (e.g. SQLite).
     */
    protected function wrapUnionMember(string $sql): string
    {
    }
    protected function shouldEmitOffset(?int $offset, ?int $limit): bool
    {
    }
    protected function compileHavingCondition(\Utopia\Query\Query $query, string $expression): string
    {
    }
    /**
     * Compile a `groupByTimeBucket` clause for this dialect.
     *
     * The default builder cannot model time bucketing portably — dialects
     * differ in both function names (e.g. `toStartOfHour` vs `date_trunc`)
     * and the set of supported buckets. Concrete dialects override this
     * method; base throws so unsupported dialects fail loudly at build time
     * rather than silently dropping the clause.
     */
    protected function compileGroupByTimeBucket(string $attribute, string $interval): string
    {
    }
    protected function compileLikePredicate(string $attribute, bool $not = false): string
    {
    }
    protected function likeEscape(): string
    {
    }
    /**
     * @param  array<mixed>  $values
     */
    protected function compileComparison(string $attribute, string $operator, array $values, ?string $column = null): string
    {
    }
    /**
     * Enclose a raw SQL condition in parentheses. Conditions are joined with
     * AND, which binds tighter than OR, so a raw `a = ? OR b = ?` left bare
     * beside a hook's `tenant_id IN (?)` would let the OR escape the tenant
     * and permission conditions.
     */
    protected function enclose(string $condition): string
    {
    }
    /**
     * Escape LIKE metacharacters in user input before wrapping with wildcards.
     */
    protected function escapeLikeValue(mixed $value): string
    {
    }
    /**
     * @param  array<mixed>  $values
     */
    #[\Override]
    protected function compileSpatialDistance(\Utopia\Query\Method $method, string $attribute, array $values): string
    {
    }
    /**
     * @param  array<mixed>  $values
     */
    #[\Override]
    protected function compileSpatialPredicate(string $function, string $attribute, array $values, bool $not): string
    {
    }
    /**
     * @param  array<mixed>  $values
     */
    #[\Override]
    protected function compileSpatialCoversPredicate(string $attribute, array $values, bool $not): string
    {
    }
    /**
     * @param  array<mixed>  $values
     */
    #[\Override]
    protected function compileJsonPathExpression(string $attribute, array $values): string
    {
    }
    protected function compileJsonFilter(\Utopia\Query\Method $method, string $attribute, \Utopia\Query\Query $query): string
    {
    }
    #[\Override]
    protected function compileConflictHeader(): string
    {
    }
    #[\Override]
    protected function compileConflictAssignment(string $wrapped): string
    {
    }
    protected function compileConflictClause(): string
    {
    }
    #[\Override]
    protected function groupConcatExpr(string $column, string $orderBy): string
    {
    }
    #[\Override]
    protected function jsonArrayAggExpr(string $column): string
    {
    }
    #[\Override]
    protected function jsonObjectAggExpr(string $keyColumn, string $valueColumn): string
    {
    }
    protected function geomFromText(int $srid): string
    {
    }
    protected function validateTable(): void
    {
    }
    protected function validateRows(string $operation): void
    {
    }
    /**
     * Validates that all rows have the same columns and returns the column list.
     *
     * @return list<string>
     */
    protected function validateAndGetColumns(): array
    {
    }
    /**
     * Append raw values produced by a sub-statement. The values arrive as a
     * `list<mixed>` (from `Statement::$bindings`) and are wrapped without a
     * column hint — the originating builder already consumed its own
     * column-aware hints when it compiled.
     *
     * @param  array<mixed>  $bindings
     */
    protected function addBindings(array $bindings): void
    {
    }
    /**
     * Compile a CASE expression to SQL, appending bindings to $this->bindings
     * in the order WHEN-value, THEN-value, ..., ELSE-value.
     */
    protected function compileCase(\Utopia\Query\Builder\Case\Expression $case): string
    {
    }
    /**
     * Compile the INSERT INTO ... VALUES portion.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    protected function compileInsertBody(): array
    {
    }
    /**
     * @return list<string>
     */
    protected function compileAssignments(): array
    {
    }
    /**
     * @param  array<string>  $parts
     */
    protected function compileWhereClauses(array &$parts, ?\Utopia\Query\Builder\ParsedQuery $grouped = null): void
    {
    }
    /**
     * @param  array<string>  $parts
     */
    protected function compileOrderAndLimit(array &$parts, ?\Utopia\Query\Builder\ParsedQuery $grouped = null): void
    {
    }
    /**
     * Hook called after JOIN clauses and before WHERE. Override to inject
     * dialect-specific clauses such as PREWHERE (ClickHouse) or ARRAY JOIN.
     * Implementations must add any bindings they emit via $this->addBindings()
     * at the moment their fragment is emitted so ordering is preserved.
     */
    protected function buildAfterJoinsClause(\Utopia\Query\Builder\ParsedQuery $grouped): string
    {
    }
    /**
     * Hook for subclasses to inject a vector distance ORDER BY expression.
     */
    protected function compileVectorOrderExpression(): ?\Utopia\Query\Builder\Condition
    {
    }
    /**
     * Resolve the placement for a join filter condition.
     * ClickHouse overrides this to always return Placement::Where since it
     * does not support subqueries in JOIN ON conditions.
     */
    protected function resolveJoinFilterPlacement(\Utopia\Query\Hook\Join\Placement $requested, bool $isCrossJoin): \Utopia\Query\Hook\Join\Placement
    {
    }
    #[\Override]
    protected function createTokenizer(): \Utopia\Query\Tokenizer\Tokenizer
    {
    }
    #[\Override]
    protected function createAstSerializer(): \Utopia\Query\AST\Serializer
    {
    }
    public function __clone(): void
    {
    }
    protected function resetGroupByModifier(): void
    {
    }
    protected function getSpatialTypeFromWkt(string $wkt): string
    {
    }
}
