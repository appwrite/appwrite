<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

namespace Utopia\Query;

use Utopia\Query\AST\Expression;
use Utopia\Query\AST\Expression\Between;
use Utopia\Query\AST\Expression\In;
use Utopia\Query\AST\Raw;
use Utopia\Query\AST\Reference\Column;
use Utopia\Query\AST\Reference\Table;
use Utopia\Query\AST\Statement\Select;
use Utopia\Query\Builder\Binding;
use Utopia\Query\Builder\Condition;
use Utopia\Query\Builder\JoinBuilder;
use Utopia\Query\Builder\Statement;
use Utopia\Query\Hook\Filter;
use Utopia\Query\Hook\Join\Placement;

abstract class Builder implements \Utopia\Query\Compiler, \Utopia\Query\Builder\Feature\Selects, \Utopia\Query\Builder\Feature\Aggregates, \Utopia\Query\Builder\Feature\Joins, \Utopia\Query\Builder\Feature\Unions, \Utopia\Query\Builder\Feature\CTEs, \Utopia\Query\Builder\Feature\Inserts, \Utopia\Query\Builder\Feature\Updates, \Utopia\Query\Builder\Feature\Deletes, \Utopia\Query\Builder\Feature\Hooks, \Utopia\Query\Builder\Feature\Windows
{
    #[\Override]
    abstract public function count(string $attribute = '*', string $alias = ''): static;
    #[\Override]
    abstract public function countDistinct(string $attribute, string $alias = ''): static;
    #[\Override]
    abstract public function sum(string $attribute, string $alias = ''): static;
    #[\Override]
    abstract public function avg(string $attribute, string $alias = ''): static;
    #[\Override]
    abstract public function min(string $attribute, string $alias = ''): static;
    #[\Override]
    abstract public function max(string $attribute, string $alias = ''): static;
    /**
     * @param  array<string>  $columns
     */
    #[\Override]
    abstract public function groupBy(array $columns): static;
    #[\Override]
    abstract public function groupByTimeBucket(string $attribute, string $interval): static;
    /**
     * @param  array<Query>  $queries
     */
    #[\Override]
    abstract public function having(array $queries): static;
    /**
     * @param  list<string>  $columns
     */
    #[\Override]
    abstract public function with(string $name, \Utopia\Query\Builder $query, array $columns = []): static;
    /**
     * @param  list<string>  $columns
     */
    #[\Override]
    abstract public function withRecursive(string $name, \Utopia\Query\Builder $query, array $columns = []): static;
    /**
     * @param  list<string>  $columns
     */
    #[\Override]
    abstract public function withRecursiveSeedStep(string $name, \Utopia\Query\Builder $seed, \Utopia\Query\Builder $step, array $columns = []): static;
    #[\Override]
    abstract public function delete(): \Utopia\Query\Builder\Statement;
    #[\Override]
    abstract public function addHook(\Utopia\Query\Hook $hook): static;
    #[\Override]
    abstract public function into(string $table): static;
    /**
     * Set an alias for the INSERT target table (e.g. INSERT INTO table AS alias).
     * Used by PostgreSQL ON CONFLICT to reference the existing row.
     */
    abstract public function insertAs(string $alias): static;
    /**
     * @param  array<string, mixed>  $row
     */
    #[\Override]
    abstract public function set(array $row): static;
    /**
     * @param  string[]  $keys
     * @param  string[]  $updateColumns
     */
    #[\Override]
    abstract public function onConflict(array $keys, array $updateColumns): static;
    /**
     * @param  list<string>  $columns
     */
    #[\Override]
    abstract public function fromSelect(array $columns, \Utopia\Query\Builder $source): static;
    #[\Override]
    abstract public function insert(): \Utopia\Query\Builder\Statement;
    #[\Override]
    abstract public function insertDefaultValues(): \Utopia\Query\Builder\Statement;
    #[\Override]
    abstract public function insertSelect(): \Utopia\Query\Builder\Statement;
    #[\Override]
    abstract public function join(string $table, string $left, string $right, string $operator = '=', string $alias = ''): static;
    #[\Override]
    abstract public function leftJoin(string $table, string $left, string $right, string $operator = '=', string $alias = ''): static;
    #[\Override]
    abstract public function rightJoin(string $table, string $left, string $right, string $operator = '=', string $alias = ''): static;
    /**
     * @param  \Closure(JoinBuilder): void  $callback
     */
    #[\Override]
    abstract public function joinWhere(string $table, \Closure $callback, \Utopia\Query\Builder\JoinType $type = \Utopia\Query\Builder\JoinType::Inner, string $alias = ''): static;
    #[\Override]
    abstract public function from(string $table = '', string $alias = ''): static;
    abstract public function fromNone(): static;
    abstract public function fromSub(\Utopia\Query\Builder $subquery, string $alias): static;
    abstract public function selectSub(\Utopia\Query\Builder $subquery, string $alias): static;
    abstract public function filterWhereIn(string $column, \Utopia\Query\Builder $subquery): static;
    abstract public function filterWhereNotIn(string $column, \Utopia\Query\Builder $subquery): static;
    abstract public function filterExists(\Utopia\Query\Builder $subquery): static;
    abstract public function filterNotExists(\Utopia\Query\Builder $subquery): static;
    /**
     * @param  string|array<string>  $columns
     * @param  list<mixed>  $bindings
     */
    #[\Override]
    abstract public function select(string|array $columns, array $bindings = []): static;
    #[\Override]
    abstract public function distinct(): static;
    /**
     * @param  array<Query>  $queries
     */
    #[\Override]
    abstract public function filter(array $queries): static;
    /**
     * @param  array<Query>  $queries
     */
    #[\Override]
    abstract public function queries(array $queries): static;
    #[\Override]
    abstract public function sortAsc(string $attribute, ?\Utopia\Query\NullsPosition $nulls = null): static;
    #[\Override]
    abstract public function sortDesc(string $attribute, ?\Utopia\Query\NullsPosition $nulls = null): static;
    #[\Override]
    abstract public function sortRandom(): static;
    #[\Override]
    abstract public function limit(int $value): static;
    #[\Override]
    abstract public function offset(int $value): static;
    #[\Override]
    abstract public function fetch(int $count, bool $withTies = false): static;
    #[\Override]
    abstract public function page(int $page, int $perPage = 25): static;
    /**
     * @param  array<mixed>|object  $value
     */
    #[\Override]
    abstract public function cursorAfter(array|object $value): static;
    /**
     * @param  array<mixed>|object  $value
     */
    #[\Override]
    abstract public function cursorBefore(array|object $value): static;
    #[\Override]
    abstract public function when(bool $condition, \Closure $callback): static;
    abstract public function beforeBuild(\Closure $callback): static;
    abstract public function afterBuild(\Closure $callback): static;
    /**
     * @param  \Closure(Statement): (array<mixed>|int)  $executor
     */
    abstract public function setExecutor(\Closure $executor): static;
    abstract public function explain(bool $analyze = false): \Utopia\Query\Builder\Statement;
    /**
     * @return array<mixed>|int
     */
    abstract public function execute(): array|int;
    #[\Override]
    abstract public function toRawSql(): string;
    /**
     * @return list<mixed>
     */
    #[\Override]
    abstract public function getBindings(): array;
    #[\Override]
    abstract public function reset(): static;
    #[\Override]
    abstract public function union(\Utopia\Query\Builder $other): static;
    #[\Override]
    abstract public function unionAll(\Utopia\Query\Builder $other): static;
    #[\Override]
    abstract public function intersect(\Utopia\Query\Builder $other): static;
    #[\Override]
    abstract public function intersectAll(\Utopia\Query\Builder $other): static;
    #[\Override]
    abstract public function except(\Utopia\Query\Builder $other): static;
    #[\Override]
    abstract public function exceptAll(\Utopia\Query\Builder $other): static;
    #[\Override]
    abstract public function update(): \Utopia\Query\Builder\Statement;
    #[\Override]
    abstract public function selectWindow(string $function, string $alias, ?array $partitionBy = null, ?array $orderBy = null, ?string $windowName = null, ?\Utopia\Query\Builder\WindowFrame $frame = null): static;
    #[\Override]
    abstract public function window(string $name, ?array $partitionBy = null, ?array $orderBy = null, ?\Utopia\Query\Builder\WindowFrame $frame = null): static;
    abstract protected function quote(string $identifier): string;
    /**
     * Compile a random ordering expression (e.g. RAND() or rand())
     */
    abstract protected function compileRandom(): string;
    /**
     * Compile a regex filter
     *
     * @param  array<mixed>  $values
     */
    abstract protected function compileRegex(string $attribute, array $values, ?string $column = null): string;
    abstract protected function buildTableClause(): string;
    /**
     * Hook called after JOIN clauses and before WHERE. Override to inject
     * dialect-specific clauses such as PREWHERE (ClickHouse) or ARRAY JOIN.
     * Implementations must add any bindings they emit via $this->addBindings()
     * at the moment their fragment is emitted so ordering is preserved.
     */
    abstract protected function buildAfterJoinsClause(\Utopia\Query\Builder\ParsedQuery $grouped): string;
    /**
     * Hook called after GROUP BY and before HAVING. Override to emit
     * dialect-specific group-by modifiers (e.g. ClickHouse WITH TOTALS).
     */
    abstract protected function buildAfterGroupByClause(): string;
    /**
     * Hook called after ORDER BY and before LIMIT. Override to emit
     * dialect-specific clauses that bind between ordering and pagination
     * (e.g. ClickHouse LIMIT BY).
     */
    abstract protected function buildAfterOrderByClause(): string;
    /**
     * Hook called at the very end of the SELECT statement (just before any
     * UNION suffix). Override to emit dialect-specific settings fragments
     * (e.g. ClickHouse SETTINGS).
     */
    abstract protected function buildSettingsClause(): string;
    /**
     * Compile a CASE expression to SQL, appending bindings to $this->bindings
     * in the order WHEN-value, THEN-value, ..., ELSE-value.
     */
    abstract protected function compileCase(\Utopia\Query\Builder\Case\Expression $case): string;
    #[\Override]
    abstract public function build(): \Utopia\Query\Builder\Statement;
    /**
     * Wrap a compound-SELECT member for inclusion in a UNION chain. The
     * default wraps each arm in parentheses, matching the shape most
     * dialects expect. Override in dialects whose parsers reject the
     * parenthesised form (e.g. SQLite).
     */
    abstract protected function wrapUnionMember(string $sql): string;
    /**
     * Compile the INSERT INTO ... VALUES portion.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    abstract protected function compileInsertBody(): array;
    /**
     * @return list<string>
     */
    abstract protected function compileAssignments(): array;
    /**
     * @param  array<string>  $parts
     */
    abstract protected function compileWhereClauses(array &$parts, ?\Utopia\Query\Builder\ParsedQuery $grouped = null): void;
    /**
     * @param  array<string>  $parts
     */
    abstract protected function compileOrderAndLimit(array &$parts, ?\Utopia\Query\Builder\ParsedQuery $grouped = null): void;
    abstract protected function shouldEmitOffset(?int $offset, ?int $limit): bool;
    /**
     * Hook for subclasses to inject a vector distance ORDER BY expression.
     */
    abstract protected function compileVectorOrderExpression(): ?\Utopia\Query\Builder\Condition;
    abstract protected function validateTable(): void;
    abstract protected function validateRows(string $operation): void;
    /**
     * Validates that all rows have the same columns and returns the column list.
     *
     * @return list<string>
     */
    abstract protected function validateAndGetColumns(): array;
    abstract public function clone(): static;
    abstract public function __clone(): void;
    #[\Override]
    abstract public function compileFilter(\Utopia\Query\Query $query): string;
    abstract protected function compileHavingCondition(\Utopia\Query\Query $query, string $expression): string;
    #[\Override]
    abstract public function compileOrder(\Utopia\Query\Query $query): string;
    #[\Override]
    abstract public function compileLimit(\Utopia\Query\Query $query): string;
    #[\Override]
    abstract public function compileOffset(\Utopia\Query\Query $query): string;
    #[\Override]
    abstract public function compileSelect(\Utopia\Query\Query $query): string;
    #[\Override]
    abstract public function compileCursor(\Utopia\Query\Query $query): string;
    #[\Override]
    abstract public function compileAggregate(\Utopia\Query\Query $query): string;
    #[\Override]
    abstract public function compileGroupBy(\Utopia\Query\Query $query): string;
    /**
     * Compile a `groupByTimeBucket` clause for this dialect.
     *
     * The default builder cannot model time bucketing portably — dialects
     * differ in both function names (e.g. `toStartOfHour` vs `date_trunc`)
     * and the set of supported buckets. Concrete dialects override this
     * method; base throws so unsupported dialects fail loudly at build time
     * rather than silently dropping the clause.
     */
    abstract protected function compileGroupByTimeBucket(string $attribute, string $interval): string;
    #[\Override]
    abstract public function compileJoin(\Utopia\Query\Query $query): string;
    abstract protected function compileJoinWithBuilder(\Utopia\Query\Query $query, \Utopia\Query\Builder\JoinBuilder $joinBuilder): string;
    abstract protected function resolveAttribute(string $attribute): string;
    abstract protected function resolveAndWrap(string $attribute): string;
    /**
     * Append a single parameter binding, capturing its source column at the
     * moment of binding so dialects with typed named placeholders can
     * resolve a registered type without a parallel array. Most call sites
     * pass just the value; the column hint is null for literals (LIMIT,
     * cursor) and for sub-statement bindings that have already been
     * compiled.
     */
    abstract protected function addBinding(mixed $value, ?string $column = null): void;
    /**
     * Append raw values produced by a sub-statement. The values arrive as a
     * `list<mixed>` (from `Statement::$bindings`) and are wrapped without a
     * column hint — the originating builder already consumed its own
     * column-aware hints when it compiled.
     *
     * @param  array<mixed>  $bindings
     */
    abstract protected function addBindings(array $bindings): void;
    /**
     * Return the binding values in compile order as a plain `list<mixed>`
     * for `Statement::$bindings`. The wrapped `Binding` objects stay
     * internal to the builder.
     *
     * @return list<mixed>
     */
    abstract protected function getBindingValues(): array;
    /**
     * @param  array<mixed>  $values
     */
    abstract protected function compileLike(string $attribute, array $values, string $prefix, string $suffix, bool $not, ?string $column = null): string;
    /**
     * @param  array<mixed>  $values
     */
    abstract protected function compileContains(string $attribute, array $values, ?string $column = null): string;
    /**
     * @param  array<mixed>  $values
     */
    abstract protected function compileContainsAll(string $attribute, array $values, ?string $column = null): string;
    /**
     * @param  array<mixed>  $values
     */
    abstract protected function compileNotContains(string $attribute, array $values, ?string $column = null): string;
    abstract protected function compileLikePredicate(string $attribute, bool $not = false): string;
    abstract protected function getLikeKeyword(): string;
    abstract protected function likeEscape(): string;
    /**
     * Escape LIKE metacharacters in user input before wrapping with wildcards.
     */
    abstract protected function escapeLikeValue(mixed $value): string;
    /**
     * Resolve the placement for a join filter condition.
     * ClickHouse overrides this to always return Placement::Where since it
     * does not support subqueries in JOIN ON conditions.
     */
    abstract protected function resolveJoinFilterPlacement(\Utopia\Query\Hook\Join\Placement $requested, bool $isCrossJoin): \Utopia\Query\Hook\Join\Placement;
    /**
     * @param  array<mixed>  $values
     */
    abstract protected function compileIn(string $attribute, array $values, ?string $column = null): string;
    /**
     * @param  array<mixed>  $values
     */
    abstract protected function compileNotIn(string $attribute, array $values, ?string $column = null): string;
    /**
     * @param  array<mixed>  $values
     */
    abstract protected function compileComparison(string $attribute, string $operator, array $values, ?string $column = null): string;
    /**
     * Enclose a raw SQL condition in parentheses. Conditions are joined with
     * AND, which binds tighter than OR, so a raw `a = ? OR b = ?` left bare
     * beside a hook's `tenant_id IN (?)` would let the OR escape the tenant
     * and permission conditions.
     */
    abstract protected function enclose(string $condition): string;
    abstract public function toAst(): \Utopia\Query\AST\Statement\Select;
    abstract protected function createTokenizer(): \Utopia\Query\Tokenizer\Tokenizer;
    abstract protected function createAstSerializer(): \Utopia\Query\AST\Serializer;
    abstract public static function fromAst(\Utopia\Query\AST\Statement\Select $ast): static;
}
