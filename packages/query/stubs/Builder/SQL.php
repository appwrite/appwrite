<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

namespace Utopia\Query\Builder;

use Utopia\Query\Query;

abstract class SQL extends \Utopia\Query\Builder implements \Utopia\Query\Builder\Feature\Locking, \Utopia\Query\Builder\Feature\Transactions, \Utopia\Query\Builder\Feature\StatisticalAggregates, \Utopia\Query\Builder\Feature\BitwiseAggregates, \Utopia\Query\Builder\Feature\RawSql, \Utopia\Query\Builder\Feature\CrossJoins
{
    abstract protected function quote(string $identifier): string;
    /**
     * Quote a single identifier without treating dots as qualifier separators.
     *
     * Use when the identifier is known to be atomic — e.g. a column name in a
     * CREATE TABLE definition where the dot is a literal part of the name
     * rather than a `schema.table.column` separator. The canonical case is
     * ClickHouse's nested-array convention (`meta.key Array(String)`) where
     * `meta.key` is a single top-level column whose name contains a dot.
     */
    abstract protected function quoteLiteral(string $identifier): string;
    #[\Override]
    abstract public function bitAnd(string $attribute, string $alias = ''): static;
    #[\Override]
    abstract public function bitOr(string $attribute, string $alias = ''): static;
    #[\Override]
    abstract public function bitXor(string $attribute, string $alias = ''): static;
    abstract public function filterJsonContains(string $attribute, mixed $value): static;
    abstract public function filterJsonNotContains(string $attribute, mixed $value): static;
    /**
     * @param  array<mixed>  $values
     */
    abstract public function filterJsonOverlaps(string $attribute, array $values): static;
    abstract public function filterJsonPath(string $attribute, string $path, string $operator, mixed $value): static;
    #[\Override]
    abstract public function crossJoin(string $table, string $alias = ''): static;
    #[\Override]
    abstract public function naturalJoin(string $table, string $alias = ''): static;
    #[\Override]
    abstract public function forUpdate(): static;
    #[\Override]
    abstract public function forShare(): static;
    #[\Override]
    abstract public function forUpdateSkipLocked(): static;
    #[\Override]
    abstract public function forUpdateNoWait(): static;
    #[\Override]
    abstract public function forShareSkipLocked(): static;
    #[\Override]
    abstract public function forShareNoWait(): static;
    /**
     * @param  list<mixed>  $bindings
     */
    abstract public function selectRaw(string $expression, array $bindings = []): static;
    #[\Override]
    abstract public function selectCast(string $column, string $type, string $alias = ''): static;
    /**
     * @param  list<mixed>  $bindings
     */
    abstract public function orderByRaw(string $expression, array $bindings = []): static;
    /**
     * @param  list<mixed>  $bindings
     */
    abstract public function groupByRaw(string $expression, array $bindings = []): static;
    /**
     * @param  list<mixed>  $bindings
     */
    abstract public function havingRaw(string $expression, array $bindings = []): static;
    /**
     * Append a raw WHERE fragment with its own bindings.
     *
     * Caller owns the SQL fragment - no column or operator validation is performed.
     * Use this sparingly; prefer `filter()` with typed `Query::*` factories when possible.
     *
     * @param  list<mixed>  $bindings
     */
    abstract public function whereRaw(string $expression, array $bindings = []): static;
    /**
     * Append a column-to-column WHERE predicate (e.g. `users.id = orders.user_id`).
     *
     * Both columns are quoted per dialect. The operator is validated against
     * an allowlist: =, !=, <>, <, >, <=, >=.
     */
    abstract public function whereColumn(string $left, string $operator, string $right): static;
    abstract public function selectCase(\Utopia\Query\Builder\Case\Expression $case): static;
    abstract public function setCase(string $column, \Utopia\Query\Builder\Case\Expression $case): static;
    /**
     * @param  list<mixed>  $bindings
     */
    #[\Override]
    abstract public function setRaw(string $column, string $expression, array $bindings = []): static;
    /**
     * @param  list<mixed>  $bindings
     */
    abstract public function conflictSetRaw(string $column, string $expression, array $bindings = []): static;
    /**
     * Register a raw expression wrapper for a column in INSERT statements.
     *
     * The expression must contain exactly one `?` placeholder which will receive
     * the column's value from each row. E.g. `ST_GeomFromText(?, 4326)`.
     *
     * @param  list<mixed>  $extraBindings  Additional bindings beyond the column value (e.g. SRID)
     */
    abstract public function insertColumnExpression(string $column, string $expression, array $extraBindings = []): static;
    #[\Override]
    abstract public function stddev(string $attribute, string $alias = ''): static;
    #[\Override]
    abstract public function stddevPop(string $attribute, string $alias = ''): static;
    #[\Override]
    abstract public function stddevSamp(string $attribute, string $alias = ''): static;
    #[\Override]
    abstract public function variance(string $attribute, string $alias = ''): static;
    #[\Override]
    abstract public function varPop(string $attribute, string $alias = ''): static;
    #[\Override]
    abstract public function varSamp(string $attribute, string $alias = ''): static;
    #[\Override]
    abstract public function begin(): \Utopia\Query\Builder\Statement;
    #[\Override]
    abstract public function commit(): \Utopia\Query\Builder\Statement;
    #[\Override]
    abstract public function rollback(): \Utopia\Query\Builder\Statement;
    #[\Override]
    abstract public function savepoint(string $name): \Utopia\Query\Builder\Statement;
    #[\Override]
    abstract public function releaseSavepoint(string $name): \Utopia\Query\Builder\Statement;
    #[\Override]
    abstract public function rollbackToSavepoint(string $name): \Utopia\Query\Builder\Statement;
    abstract protected function compileConflictHeader(): string;
    abstract protected function compileConflictAssignment(string $wrapped): string;
    abstract protected function compileConflictClause(): string;
    #[\Override]
    abstract public function compileFilter(\Utopia\Query\Query $query): string;
    abstract protected function compileArrayFilter(\Utopia\Query\Method $method, string $attribute, \Utopia\Query\Query $query): string;
    abstract protected function compileSpatialFilter(\Utopia\Query\Method $method, string $attribute, \Utopia\Query\Query $query): string;
    /**
     * @param  array<mixed>  $values
     */
    abstract protected function compileSpatialDistance(\Utopia\Query\Method $method, string $attribute, array $values): string;
    /**
     * @param  array<mixed>  $values
     */
    abstract protected function compileSpatialPredicate(string $function, string $attribute, array $values, bool $not): string;
    /**
     * Compile covers/not-covers spatial predicate. MySQL uses ST_Contains, PostgreSQL uses ST_Covers.
     *
     * @param  array<mixed>  $values
     */
    abstract protected function compileSpatialCoversPredicate(string $attribute, array $values, bool $not): string;
    /**
     * @param  array<mixed>  $values
     */
    abstract protected function compileSearchExpression(string $attribute, array $values, bool $not): string;
    abstract protected function compileJsonFilter(\Utopia\Query\Method $method, string $attribute, \Utopia\Query\Query $query): string;
    /**
     * @param  array<mixed>  $values
     */
    abstract protected function compileJsonContainsExpression(string $attribute, array $values, bool $not): string;
    /**
     * @param  array<mixed>  $values
     */
    abstract protected function compileJsonOverlapsExpression(string $attribute, array $values): string;
    /**
     * @param  array<mixed>  $values
     */
    abstract protected function compileJsonPathExpression(string $attribute, array $values): string;
}
