<?php

namespace Utopia\Query\Builder\Trait;

use Closure;
use Utopia\Query\Builder;
use Utopia\Query\Builder\Condition;
use Utopia\Query\Builder\ExistsSubquery;
use Utopia\Query\Builder\Statement;
use Utopia\Query\Builder\SubSelect;
use Utopia\Query\Builder\WhereInSubquery;
use Utopia\Query\Exception\ValidationException;
use Utopia\Query\NullsPosition;
use Utopia\Query\Query;

trait Selects
{
    #[\Override]
    public function from(string $table = '', string $alias = ''): static
    {
        $this->table = $table;
        $this->alias = $alias;
        $this->fromSubquery = null;
        $this->tableless = ($table === '');

        return $this;
    }

    public function fromNone(): static
    {
        return $this->from('');
    }

    public function fromSub(Builder $subquery, string $alias): static
    {
        $this->fromSubquery = new SubSelect($subquery, $alias);
        $this->table = '';

        return $this;
    }

    public function selectSub(Builder $subquery, string $alias): static
    {
        $this->subSelects[] = new SubSelect($subquery, $alias);

        return $this;
    }

    public function filterWhereIn(string $column, Builder $subquery): static
    {
        $this->whereInSubqueries[] = new WhereInSubquery($column, $subquery, false);

        return $this;
    }

    public function filterWhereNotIn(string $column, Builder $subquery): static
    {
        $this->whereInSubqueries[] = new WhereInSubquery($column, $subquery, true);

        return $this;
    }

    public function filterExists(Builder $subquery): static
    {
        $this->existsSubqueries[] = new ExistsSubquery($subquery, false);

        return $this;
    }

    public function filterNotExists(Builder $subquery): static
    {
        $this->existsSubqueries[] = new ExistsSubquery($subquery, true);

        return $this;
    }

    /**
     * @param  string|array<string>  $columns
     * @param  list<mixed>  $bindings
     */
    #[\Override]
    public function select(string|array $columns, array $bindings = []): static
    {
        if (\is_string($columns)) {
            $this->rawSelects[] = new Condition($columns, $bindings);
        } else {
            $this->pendingQueries[] = Query::select($columns);
        }

        return $this;
    }

    #[\Override]
    public function distinct(): static
    {
        $this->pendingQueries[] = Query::distinct();

        return $this;
    }

    /**
     * @param  array<Query>  $queries
     */
    #[\Override]
    public function filter(array $queries): static
    {
        foreach ($queries as $query) {
            $this->pendingQueries[] = $query;
        }

        return $this;
    }

    /**
     * @param  array<Query>  $queries
     */
    #[\Override]
    public function queries(array $queries): static
    {
        foreach ($queries as $query) {
            $this->pendingQueries[] = $query;
        }

        return $this;
    }

    #[\Override]
    public function sortAsc(string $attribute, ?NullsPosition $nulls = null): static
    {
        $this->pendingQueries[] = Query::orderAsc($attribute, $nulls);

        return $this;
    }

    #[\Override]
    public function sortDesc(string $attribute, ?NullsPosition $nulls = null): static
    {
        $this->pendingQueries[] = Query::orderDesc($attribute, $nulls);

        return $this;
    }

    #[\Override]
    public function sortRandom(): static
    {
        $this->pendingQueries[] = Query::orderRandom();

        return $this;
    }

    #[\Override]
    public function limit(int $value): static
    {
        $this->pendingQueries[] = Query::limit($value);

        return $this;
    }

    #[\Override]
    public function offset(int $value): static
    {
        $this->pendingQueries[] = Query::offset($value);

        return $this;
    }

    #[\Override]
    public function fetch(int $count, bool $withTies = false): static
    {
        $this->fetchCount = $count;
        $this->fetchWithTies = $withTies;

        return $this;
    }

    #[\Override]
    public function page(int $page, int $perPage = 25): static
    {
        if ($page < 1) {
            throw new ValidationException('Page must be >= 1, got ' . $page);
        }
        if ($perPage < 1) {
            throw new ValidationException('Per page must be >= 1, got ' . $perPage);
        }

        $this->pendingQueries[] = Query::limit($perPage);
        $this->pendingQueries[] = Query::offset(($page - 1) * $perPage);

        return $this;
    }

    #[\Override]
    public function cursorAfter(mixed $value): static
    {
        $this->pendingQueries[] = Query::cursorAfter($value);

        return $this;
    }

    #[\Override]
    public function cursorBefore(mixed $value): static
    {
        $this->pendingQueries[] = Query::cursorBefore($value);

        return $this;
    }

    #[\Override]
    public function when(bool $condition, Closure $callback): static
    {
        if ($condition) {
            $callback($this);
        }

        return $this;
    }

    public function beforeBuild(Closure $callback): static
    {
        $this->beforeBuildCallbacks[] = $callback;

        return $this;
    }

    public function afterBuild(Closure $callback): static
    {
        $this->afterBuildCallbacks[] = $callback;

        return $this;
    }

    /**
     * @param  \Closure(Statement): (array<mixed>|int)  $executor
     */
    public function setExecutor(\Closure $executor): static
    {
        $this->executor = $executor;

        return $this;
    }

    public function explain(bool $analyze = false): Statement
    {
        $result = $this->build();
        $prefix = $analyze ? 'EXPLAIN ANALYZE ' : 'EXPLAIN ';

        return new Statement($prefix . $result->query, $result->bindings, readOnly: true, executor: $this->executor);
    }

    /**
     * @return array<mixed>|int
     */
    public function execute(): array|int
    {
        return $this->build()->execute();
    }

    #[\Override]
    public function toRawSql(): string
    {
        $result = $this->build();
        $sql = $result->query;
        $offset = 0;

        foreach ($result->bindings as $binding) {
            if (\is_string($binding)) {
                $value = "'" . str_replace("'", "''", $binding) . "'";
            } elseif (\is_int($binding) || \is_float($binding)) {
                $value = (string) $binding;
            } elseif (\is_bool($binding)) {
                $value = $binding ? '1' : '0';
            } else {
                $value = 'NULL';
            }

            $pos = \strpos($sql, '?', $offset);
            if ($pos !== false) {
                $sql = \substr_replace($sql, $value, $pos, 1);
                $offset = $pos + \strlen($value);
            }
        }

        return $sql;
    }

    /**
     * @return list<mixed>
     */
    #[\Override]
    public function getBindings(): array
    {
        return $this->getBindingValues();
    }

    #[\Override]
    public function reset(): static
    {
        $this->pendingQueries = [];
        $this->bindings = [];
        $this->resolvedAttributeCache = [];
        $this->table = '';
        $this->alias = '';
        $this->unions = [];
        $this->rows = [];
        $this->rawSets = [];
        $this->rawSetBindings = [];
        $this->conflictKeys = [];
        $this->conflictUpdateColumns = [];
        $this->conflictRawSets = [];
        $this->conflictRawSetBindings = [];
        $this->insertColumnExpressions = [];
        $this->insertColumnExpressionBindings = [];
        $this->insertAlias = '';
        $this->lockMode = null;
        $this->lockOfTable = null;
        $this->insertSelectSource = null;
        $this->insertSelectColumns = [];
        $this->ctes = [];
        $this->rawSelects = [];
        $this->windowSelects = [];
        $this->windowDefinitions = [];
        $this->sample = null;
        $this->cases = [];
        $this->caseSets = [];
        $this->whereInSubqueries = [];
        $this->subSelects = [];
        $this->fromSubquery = null;
        $this->tableless = false;
        $this->rawOrders = [];
        $this->rawGroups = [];
        $this->rawHavings = [];
        $this->rawWheres = [];
        $this->columnPredicates = [];
        $this->joins = [];
        $this->existsSubqueries = [];
        $this->lateralJoins = [];
        $this->beforeBuildCallbacks = [];
        $this->afterBuildCallbacks = [];
        $this->fetchCount = null;
        $this->fetchWithTies = false;
        // Transient build state — set by prepareAliasQualification() on every
        // build(). Clearing them here keeps reset() audit-complete: every
        // field mutated in build*/compile* paths is reset. Hook arrays and
        // the executor closure are user-installed infrastructure (see
        // testResetPreservesAttributeResolver / testResetPreservesConditionProviders)
        // and intentionally survive reset().
        $this->qualify = false;
        $this->aggregationAliases = [];

        return $this;
    }

}
