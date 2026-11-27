<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

namespace Utopia\Query\Builder;

use Utopia\Query\AST\Expression;
use Utopia\Query\AST\Expression\Between;
use Utopia\Query\AST\Expression\In;
use Utopia\Query\AST\Raw;
use Utopia\Query\AST\Reference\Column;
use Utopia\Query\AST\Reference\Table;
use Utopia\Query\AST\Statement\Select;
use Utopia\Query\Builder;
use Utopia\Query\Builder\MongoDB\Operation;
use Utopia\Query\Hook;
use Utopia\Query\Hook\Attribute;
use Utopia\Query\Hook\Filter;
use Utopia\Query\Hook\Join\Placement;
use Utopia\Query\Method;
use Utopia\Query\Query;

class MongoDB extends \Utopia\Query\Builder implements \Utopia\Query\Builder\Feature\Upsert, \Utopia\Query\Builder\Feature\InsertOrIgnore, \Utopia\Query\Builder\Feature\FullTextSearch, \Utopia\Query\Builder\Feature\TableSampling, \Utopia\Query\Builder\Feature\MongoDB\FieldUpdates, \Utopia\Query\Builder\Feature\MongoDB\ArrayPushModifiers, \Utopia\Query\Builder\Feature\MongoDB\ConditionalArrayUpdates, \Utopia\Query\Builder\Feature\MongoDB\PipelineStages, \Utopia\Query\Builder\Feature\MongoDB\AtlasSearch
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
    /**
     * {@inheritDoc}
     *
     * Hook\Filter and Hook\Join\Filter return a {@see Condition}: a raw SQL
     * expression plus bindings. A MongoDB operation document has nowhere to put
     * one, so they are rejected rather than accepted and dropped -- silently
     * ignoring a Hook\Filter\Tenant would leave every query unscoped.
     *
     * Hook\Attribute and Hook\Write are dialect-neutral and still apply.
     */
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
     * @param array<mixed> $values
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
    #[\Override]
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
    public function upsert(): \Utopia\Query\Builder\Statement
    {
    }
    #[\Override]
    public function insertOrIgnore(): \Utopia\Query\Builder\Statement
    {
    }
    public function explain(bool $analyze = false): \Utopia\Query\Builder\Statement
    {
    }
    #[\Override]
    public function filterSearch(string $attribute, string $value): static
    {
    }
    #[\Override]
    public function tablesample(float $percent, string $method = 'BERNOULLI'): static
    {
    }
    public function push(string $field, mixed $value): static
    {
    }
    public function pull(string $field, mixed $value): static
    {
    }
    public function addToSet(string $field, mixed $value): static
    {
    }
    public function unsetFields(string ...$fields): static
    {
    }
    #[\Override]
    public function rename(string $oldField, string $newField): static
    {
    }
    #[\Override]
    public function multiply(string $field, int|float $factor): static
    {
    }
    #[\Override]
    public function popFirst(string $field): static
    {
    }
    #[\Override]
    public function popLast(string $field): static
    {
    }
    /**
     * @param  array<mixed>  $values
     */
    #[\Override]
    public function pullAll(string $field, array $values): static
    {
    }
    #[\Override]
    public function updateMin(string $field, mixed $value): static
    {
    }
    #[\Override]
    public function updateMax(string $field, mixed $value): static
    {
    }
    #[\Override]
    public function currentDate(string $field, string $type = 'date'): static
    {
    }
    /**
     * @param  array<mixed>  $values
     * @param  array<string, int>|null  $sort
     */
    #[\Override]
    public function pushEach(string $field, array $values, ?int $position = null, ?int $slice = null, ?array $sort = null): static
    {
    }
    /**
     * Register an arrayFilters document for a positional filtered update such
     * as `field.$[identifier]`. The identifier argument is informational and
     * must appear as the root-level path in $condition (e.g. identifier
     * 'elem' paired with `['elem.grade' => ['$gte' => 85]]`).
     *
     * @param  array<string, mixed>  $condition
     */
    #[\Override]
    public function arrayFilter(string $identifier, array $condition): static
    {
    }
    /**
     * @param  array<int|float>  $boundaries
     * @param  array<string, mixed>  $output
     */
    #[\Override]
    public function bucket(string $groupBy, array $boundaries, ?string $defaultBucket = null, array $output = []): static
    {
    }
    /**
     * @param  array<string, mixed>  $output
     */
    #[\Override]
    public function bucketAuto(string $groupBy, int $buckets, array $output = []): static
    {
    }
    #[\Override]
    public function graphLookup(string $from, string $startWith, string $connectFromField, string $connectToField, string $as, ?int $maxDepth = null, ?string $depthField = null): static
    {
    }
    /**
     * @param  array<mixed>|null  $on
     * @param  array<mixed>|null  $whenMatched
     * @param  array<mixed>|null  $whenNotMatched
     */
    #[\Override]
    public function mergeIntoCollection(string $collection, ?array $on = null, ?array $whenMatched = null, ?array $whenNotMatched = null): static
    {
    }
    #[\Override]
    public function outputToCollection(string $collection, ?string $database = null): static
    {
    }
    #[\Override]
    public function replaceRoot(string $newRootExpression): static
    {
    }
    /**
     * @param  array<string, mixed>  $searchDefinition
     */
    #[\Override]
    public function search(array $searchDefinition, ?string $index = null): static
    {
    }
    /**
     * @param  array<string, mixed>  $searchDefinition
     */
    #[\Override]
    public function searchMeta(array $searchDefinition, ?string $index = null): static
    {
    }
    /**
     * @param  array<float>  $queryVector
     * @param  array<string, mixed>|null  $filter
     */
    #[\Override]
    public function vectorSearch(string $path, array $queryVector, int $numCandidates, int $limit, ?string $index = null, ?array $filter = null): static
    {
    }
    /**
     * @param  array<string, \Utopia\Query\Builder>  $facets
     */
    #[\Override]
    public function facet(array $facets): static
    {
    }
    public function increment(string $field, int|float $amount = 1): static
    {
    }
    /**
     * @param string|array<string, int> $hint
     */
    public function hint(string|array $hint): static
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
    protected function createTokenizer(): \Utopia\Query\Tokenizer\Tokenizer
    {
    }
    protected function createAstSerializer(): \Utopia\Query\AST\Serializer
    {
    }
    public function __clone(): void
    {
    }
}
