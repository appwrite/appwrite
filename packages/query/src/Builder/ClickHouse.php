<?php

namespace Utopia\Query\Builder;

use Utopia\Query\Builder as BaseBuilder;
use Utopia\Query\Builder\ClickHouse\Format;
use Utopia\Query\Builder\ClickHouse\FormattedInsertStatement;
use Utopia\Query\Builder\Feature\BitwiseAggregates;
use Utopia\Query\Builder\Feature\ClickHouse\ApproximateAggregates;
use Utopia\Query\Builder\Feature\ClickHouse\ArrayJoins;
use Utopia\Query\Builder\Feature\ClickHouse\AsofJoins;
use Utopia\Query\Builder\Feature\ClickHouse\LimitBy;
use Utopia\Query\Builder\Feature\ClickHouse\WithFill;
use Utopia\Query\Builder\Feature\ConditionalAggregates;
use Utopia\Query\Builder\Feature\CrossJoins;
use Utopia\Query\Builder\Feature\Cube;
use Utopia\Query\Builder\Feature\FullOuterJoins;
use Utopia\Query\Builder\Feature\Hints;
use Utopia\Query\Builder\Feature\RawSql;
use Utopia\Query\Builder\Feature\Rollup;
use Utopia\Query\Builder\Feature\StatisticalAggregates;
use Utopia\Query\Builder\Feature\StringAggregates;
use Utopia\Query\Builder\Feature\TableSampling;
use Utopia\Query\Builder\Feature\Totals;
use Utopia\Query\Exception\ValidationException;
use Utopia\Query\Hook\Join\Placement;
use Utopia\Query\Query;
use Utopia\Query\QuotesIdentifiers;

class ClickHouse extends BaseBuilder implements Hints, ConditionalAggregates, TableSampling, FullOuterJoins, StringAggregates, StatisticalAggregates, BitwiseAggregates, LimitBy, ArrayJoins, AsofJoins, WithFill, Rollup, Cube, Totals, ApproximateAggregates, RawSql, CrossJoins
{
    use QuotesIdentifiers;
    use Trait\BitwiseAggregates;
    use Trait\ClickHouse\ApproximateAggregates;
    use Trait\ClickHouse\ArrayJoins;
    use Trait\ClickHouse\AsofJoins;
    use Trait\ClickHouse\LimitBy;
    use Trait\ClickHouse\WithFill;
    use Trait\FullOuterJoins;
    use Trait\CrossJoins;
    use Trait\GroupByModifiers;
    use Trait\RawSql;
    use Trait\StatisticalAggregates;
    use Trait\StringAggregates;

    /**
     * @var array<Query>
     */
    protected array $prewhereQueries = [];

    protected bool $useFinal = false;

    protected ?float $sampleFraction = null;

    /** @var list<string> */
    protected array $hints = [];

    /** @var ?array{count: int, columns: list<string>} */
    protected ?array $limitByClause = null;

    /** @var list<array{type: string, column: string, alias: string}> */
    protected array $arrayJoins = [];

    /** @var list<string> */
    protected array $rawJoinClauses = [];

    protected ?string $insertFormat = null;

    /** @var list<string> */
    protected array $insertFormatColumns = [];

    /**
     * Caller-registered column → ClickHouse type map. Populated via
     * `withParamType()`; consumed at compile-time to attach `{name:Type}`
     * placeholder metadata to bindings whose column we recognise.
     *
     * @var array<string, string>
     */
    protected array $paramTypes = [];

    /**
     * Whether to rewrite `?` placeholders to ClickHouse `{name:Type}` form
     * at Statement creation time. Enabled by `useNamedBindings()`.
     */
    protected bool $namedBindings = false;

    public const DELETE_MODE_LIGHTWEIGHT = 'lightweight';

    public const DELETE_MODE_MUTATION = 'mutation';

    /**
     * Which DELETE form `delete()` emits. Lightweight by default — matches
     * the ClickHouse server default and is the form most callers want for
     * row-level cleanup. The mutation form is heavier (rewrites parts
     * asynchronously) and is opt-in via `deleteMode('mutation')`.
     */
    protected string $deleteMode = self::DELETE_MODE_LIGHTWEIGHT;

    /**
     * Add PREWHERE filters (evaluated before reading all columns — major ClickHouse optimization)
     *
     * @param  array<Query>  $queries
     */
    public function prewhere(array $queries): static
    {
        foreach ($queries as $query) {
            $this->prewhereQueries[] = $query;
        }

        return $this;
    }

    /**
     * Add FINAL keyword after table name (forces merging of data parts)
     */
    public function final(): static
    {
        $this->useFinal = true;

        return $this;
    }

    /**
     * Add SAMPLE clause after table name (approximate query processing)
     */
    public function sample(float $fraction): static
    {
        if ($fraction <= 0.0 || $fraction >= 1.0) {
            throw new ValidationException('Sample fraction must be between 0 and 1 exclusive');
        }

        $this->sampleFraction = $fraction;

        return $this;
    }

    #[\Override]
    public function hint(string $hint): static
    {
        if (!\preg_match('/^[A-Za-z0-9_=., ]+$/', $hint)) {
            throw new ValidationException('Invalid hint: ' . $hint);
        }

        $this->hints[] = $hint;

        return $this;
    }

    /**
     * Recommended bulk-ingest entry point. Emits the canonical `INSERT INTO
     * <table> [(<cols>)] FORMAT <name>` envelope alongside the serialized row
     * payload in a single typed call. The returned `FormattedInsertStatement`
     * exposes `->query` (envelope, no bindings) and `->body` (format-specific
     * payload) so the caller can ship both to ClickHouse's HTTP interface
     * without hand-assembling either side.
     *
     * The target table must be set via `into()` first. Columns are derived
     * from the keys of the first row when `$columns` is omitted; pass
     * `$columns` explicitly to pin the order when row shapes vary or when
     * an empty iterable is passed. An empty iterable produces an empty
     * body — ClickHouse accepts this as a zero-row ingest.
     *
     * @param  iterable<array<string, mixed>>  $rows
     * @param  list<string>  $columns  Optional explicit column ordering.
     */
    public function bulkInsert(Format $format, iterable $rows, array $columns = []): FormattedInsertStatement
    {
        $materialized = [];
        foreach ($rows as $row) {
            /** @phpstan-ignore function.alreadyNarrowedType */
            if (!\is_array($row)) {
                throw new ValidationException('bulkInsert() rows must be associative arrays.');
            }
            $materialized[] = $row;
        }

        if (empty($columns) && !empty($materialized)) {
            $columns = \array_keys($materialized[0]);
        }

        $sql = $this->compileFormatInsertEnvelope($format->value, $columns);

        $body = $format->serialize($materialized, empty($columns) ? null : $columns);

        return new FormattedInsertStatement(
            $sql,
            [],
            $columns,
            $format->value,
            $body,
            executor: $this->executor,
        );
    }

    /**
     * Lower-level setter for the FORMAT envelope. Use `bulkInsert()` for the
     * typed entry point; this method is retained for callers that need to
     * stream the body payload separately (e.g. piping a pre-serialized stream
     * straight into the HTTP request) — the subsequent `insert()` call emits
     * the envelope only, with `body = null`.
     *
     * Column names are derived from the most recent `set()` call (values are
     * ignored). Pass `$columns` to declare them explicitly when no `set()`
     * call has been made.
     *
     * @param  list<string>  $columns
     */
    public function insertFormat(string $format, array $columns = []): static
    {
        if (!\preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $format)) {
            throw new ValidationException('Invalid ClickHouse INSERT format: ' . $format);
        }

        $this->insertFormat = $format;
        $this->insertFormatColumns = $columns;

        return $this;
    }

    /**
     * Build the shared `INSERT INTO <table> [(<cols>)] FORMAT <name>`
     * envelope. Validates the table, validates column names, quotes the
     * table identifier, and wraps each column via `resolveAndWrap()`.
     * Resets bindings so callers don't accumulate stale values from prior
     * builder operations.
     *
     * @param  list<string>  $columns
     */
    private function compileFormatInsertEnvelope(string $format, array $columns): string
    {
        $this->bindings = [];
        $this->validateTable();

        foreach ($columns as $col) {
            if ($col === '') {
                throw new ValidationException('Column names for FORMAT INSERT must be non-empty strings.');
            }
        }

        $wrappedColumns = empty($columns)
            ? ''
            : ' (' . \implode(', ', \array_map(
                fn (string $col): string => $this->resolveAndWrap($col),
                $columns
            )) . ')';

        return 'INSERT INTO ' . $this->quote($this->table)
            . $wrappedColumns
            . ' FORMAT ' . $format;
    }

    /**
     * @param  array<string, string>  $settings
     */
    public function settings(array $settings): static
    {
        foreach ($settings as $key => $value) {
            if (!\preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key)) {
                throw new ValidationException('Invalid ClickHouse setting key: ' . $key);
            }

            $value = (string) $value;

            if (!\preg_match('/^[a-zA-Z0-9_.]+$/', $value)) {
                throw new ValidationException('Invalid ClickHouse setting value: ' . $value);
            }

            $this->hints[] = $key . '=' . $value;
        }

        return $this;
    }

    #[\Override]
    public function tablesample(float $percent, string $method = 'BERNOULLI'): static
    {
        return $this->sample($percent / 100);
    }

    #[\Override]
    public function countWhen(string $condition, string $alias = '', mixed ...$bindings): static
    {
        return $this->aggregateFilter('count', null, $condition, $alias, \array_values($bindings));
    }

    #[\Override]
    public function sumWhen(string $column, string $condition, string $alias = '', mixed ...$bindings): static
    {
        return $this->aggregateFilter('sum', $column, $condition, $alias, \array_values($bindings));
    }

    #[\Override]
    public function avgWhen(string $column, string $condition, string $alias = '', mixed ...$bindings): static
    {
        return $this->aggregateFilter('avg', $column, $condition, $alias, \array_values($bindings));
    }

    #[\Override]
    public function minWhen(string $column, string $condition, string $alias = '', mixed ...$bindings): static
    {
        return $this->aggregateFilter('min', $column, $condition, $alias, \array_values($bindings));
    }

    #[\Override]
    public function maxWhen(string $column, string $condition, string $alias = '', mixed ...$bindings): static
    {
        return $this->aggregateFilter('max', $column, $condition, $alias, \array_values($bindings));
    }

    /**
     * Emit a conditional aggregate using ClickHouse's `-If` combinator.
     *
     * @param  list<mixed>  $bindings
     */
    private function aggregateFilter(string $aggregate, ?string $column, string $condition, string $alias, array $bindings): static
    {
        $arguments = $column === null
            ? $condition
            : $this->resolveAndWrap($column) . ', ' . $condition;
        $expr = $aggregate . 'If(' . $arguments . ')';
        if ($alias !== '') {
            $expr .= ' AS ' . $this->quote($alias);
        }

        return $this->select($expr, $bindings);
    }

    /**
     * ClickHouse has no bare STDDEV function. Emit stddevPop (population
     * standard deviation) which matches the ISO SQL standard semantics.
     */
    #[\Override]
    public function stddev(string $attribute, string $alias = ''): static
    {
        $expr = 'stddevPop(' . $this->resolveAndWrap($attribute) . ')';
        if ($alias !== '') {
            $expr .= ' AS ' . $this->quote($alias);
        }

        return $this->select($expr);
    }

    /**
     * ClickHouse has no bare VARIANCE function. Emit varPop (population
     * variance) which matches the ISO SQL standard semantics.
     */
    #[\Override]
    public function variance(string $attribute, string $alias = ''): static
    {
        $expr = 'varPop(' . $this->resolveAndWrap($attribute) . ')';
        if ($alias !== '') {
            $expr .= ' AS ' . $this->quote($alias);
        }

        return $this->select($expr);
    }

    #[\Override]
    protected function groupConcatExpr(string $column, string $orderBy): string
    {
        return 'arrayStringConcat(groupArray(' . $column . '), ?)';
    }

    #[\Override]
    protected function jsonArrayAggExpr(string $column): string
    {
        return 'toJSONString(groupArray(' . $column . '))';
    }

    #[\Override]
    protected function jsonObjectAggExpr(string $keyColumn, string $valueColumn): string
    {
        return 'toJSONString(CAST((groupArray(' . $keyColumn . '), groupArray(' . $valueColumn . ')) AS Map(String, String)))';
    }

    #[\Override]
    public function withTotals(): static
    {
        $this->groupByModifier = 'WITH TOTALS';

        return $this;
    }

    #[\Override]
    public function withRollup(): static
    {
        $this->groupByModifier = 'WITH ROLLUP';

        return $this;
    }

    #[\Override]
    public function withCube(): static
    {
        $this->groupByModifier = 'WITH CUBE';

        return $this;
    }

    #[\Override]
    public function reset(): static
    {
        parent::reset();
        $this->prewhereQueries = [];
        $this->useFinal = false;
        $this->sampleFraction = null;
        $this->hints = [];
        $this->limitByClause = null;
        $this->arrayJoins = [];
        $this->rawJoinClauses = [];
        $this->insertFormat = null;
        $this->insertFormatColumns = [];
        $this->deleteMode = self::DELETE_MODE_LIGHTWEIGHT;
        $this->namedBindings = false;
        $this->paramTypes = [];
        $this->resetGroupByModifier();

        return $this;
    }

    /**
     * Enable rewriting of `?` placeholders to ClickHouse `{name:Type}` form
     * at Statement-emission time. Off by default — the positional form is
     * what every other dialect uses and what the existing test fixtures
     * expect.
     */
    public function useNamedBindings(bool $enabled = true): static
    {
        $this->namedBindings = $enabled;

        return $this;
    }

    /**
     * Register a ClickHouse type for a column. When a `?` placeholder is
     * produced for a binding whose column hint matches `$column`, the
     * rewritten placeholder uses `$type`. Otherwise we fall back to the
     * type inference rules in `inferClickHouseType()`.
     */
    public function withParamType(string $column, string $type): static
    {
        if (! \preg_match('/^[A-Za-z][A-Za-z0-9_]*(?:\((?:[^()]*|\([^()]*\))*\))?$/', $type)) {
            throw new ValidationException('Invalid ClickHouse type: ' . $type);
        }

        $this->paramTypes[$column] = $type;

        return $this;
    }

    /**
     * @param  array<string, string>  $types
     */
    public function withParamTypes(array $types): static
    {
        foreach ($types as $column => $type) {
            $this->withParamType($column, $type);
        }

        return $this;
    }

    /**
     * Infer a ClickHouse type from a PHP value when no explicit registration
     * is available. Covers the four scalars used by the audit and usage
     * schemas plus DateTime objects. Falls back to `String`, which is the
     * safest default for unknown payloads.
     */
    private function inferClickHouseType(mixed $value): string
    {
        return match (true) {
            \is_int($value) => 'Int64',
            \is_float($value) => 'Float64',
            \is_bool($value) => 'UInt8',
            $value === null => 'Nullable(String)',
            $value instanceof \DateTimeInterface => 'DateTime64(3)',
            default => 'String',
        };
    }

    /**
     * Resolve the ClickHouse type for the `$index`-th positional binding,
     * preferring an explicit `withParamType()` registration over inference.
     */
    private function resolveBindingType(int $index): string
    {
        $binding = $this->bindings[$index] ?? null;

        if ($binding !== null && $binding->column !== null && isset($this->paramTypes[$binding->column])) {
            return $this->paramTypes[$binding->column];
        }

        return $this->inferClickHouseType($binding?->value);
    }

    /**
     * Rewrite a `?`-placeholder statement to ClickHouse `{paramN:Type}`
     * form, attaching `namedBindings` to the returned Statement so HTTP
     * callers can post parameters by name.
     *
     * The positional `$stmt->bindings` array stays intact so existing
     * callers that read it unchanged keep working.
     */
    protected function applyNamedTypedBindings(Statement $stmt): Statement
    {
        if (! $this->namedBindings) {
            return $stmt;
        }

        $sql = $stmt->query;
        $bindings = $stmt->bindings;

        if (\count($bindings) === 0) {
            return $stmt;
        }

        $named = [];
        $index = 0;
        $rewritten = \preg_replace_callback(
            '/(?<!\?)\?(?![|&?])/',
            function () use (&$index, &$named, $bindings): string {
                $type = $this->resolveBindingType($index);
                $name = 'param' . $index;
                $named[$name] = $bindings[$index] ?? null;
                $index++;

                return '{' . $name . ':' . $type . '}';
            },
            $sql
        );

        if ($rewritten === null) {
            return $stmt;
        }

        return new Statement(
            $rewritten,
            $bindings,
            $stmt->readOnly,
            $this->executor,
            namedBindings: $named,
        );
    }

    #[\Override]
    protected function compileRandom(): string
    {
        return 'rand()';
    }

    /**
     * Map a supported `groupByTimeBucket` interval to its ClickHouse
     * `toStartOf*` function name.
     */
    private const array TIME_BUCKET_FUNCTIONS = [
        '1m' => 'toStartOfMinute',
        '5m' => 'toStartOfFiveMinutes',
        '15m' => 'toStartOfFifteenMinutes',
        '1h' => 'toStartOfHour',
        '1d' => 'toStartOfDay',
        '1w' => 'toStartOfWeek',
        '1M' => 'toStartOfMonth',
    ];

    #[\Override]
    protected function compileGroupByTimeBucket(string $attribute, string $interval): string
    {
        $function = self::TIME_BUCKET_FUNCTIONS[$interval] ?? null;

        if ($function === null) {
            throw new ValidationException(
                'Invalid groupByTimeBucket interval for ClickHouse: ' . $interval
            );
        }

        return $function . '(' . $this->resolveAndWrap($attribute) . ')';
    }

    /**
     * ClickHouse uses the match(column, pattern) function instead of REGEXP
     *
     * @param  array<mixed>  $values
     */
    #[\Override]
    protected function compileRegex(string $attribute, array $values, ?string $column = null): string
    {
        $this->addBinding($values[0], $column);

        return 'match(' . $attribute . ', ?)';
    }

    /**
     * ClickHouse uses startsWith()/endsWith() functions instead of LIKE with wildcards.
     *
     * @param  array<mixed>  $values
     */
    #[\Override]
    protected function compileLike(string $attribute, array $values, string $prefix, string $suffix, bool $not, ?string $column = null): string
    {
        /** @var string $rawVal */
        $rawVal = $values[0];

        // startsWith: prefix='', suffix='%'
        if ($prefix === '' && $suffix === '%') {
            $func = $not ? 'NOT startsWith' : 'startsWith';
            $this->addBinding($rawVal, $column);

            return $func . '(' . $attribute . ', ?)';
        }

        // endsWith: prefix='%', suffix=''
        if ($prefix === '%' && $suffix === '') {
            $func = $not ? 'NOT endsWith' : 'endsWith';
            $this->addBinding($rawVal, $column);

            return $func . '(' . $attribute . ', ?)';
        }

        // Fallback for any other LIKE pattern (should not occur in practice)
        $val = $this->escapeLikeValue($rawVal);
        $this->addBinding($prefix . $val . $suffix, $column);
        $keyword = $not ? 'NOT LIKE' : 'LIKE';

        return $attribute . ' ' . $keyword . ' ?';
    }

    /**
     * ClickHouse uses position() instead of LIKE '%val%' for substring matching.
     *
     * @param  array<mixed>  $values
     */
    #[\Override]
    protected function compileContains(string $attribute, array $values, ?string $column = null): string
    {
        /** @var array<string> $values */
        if (\count($values) === 1) {
            $this->addBinding($values[0], $column);

            return 'position(' . $attribute . ', ?) > 0';
        }

        $parts = [];
        foreach ($values as $value) {
            $this->addBinding($value, $column);
            $parts[] = 'position(' . $attribute . ', ?) > 0';
        }

        return '(' . \implode(' OR ', $parts) . ')';
    }

    /**
     * ClickHouse uses position() instead of LIKE '%val%' for substring matching (all values).
     *
     * @param  array<mixed>  $values
     */
    #[\Override]
    protected function compileContainsAll(string $attribute, array $values, ?string $column = null): string
    {
        /** @var array<string> $values */
        $parts = [];
        foreach ($values as $value) {
            $this->addBinding($value, $column);
            $parts[] = 'position(' . $attribute . ', ?) > 0';
        }

        return '(' . \implode(' AND ', $parts) . ')';
    }

    /**
     * ClickHouse uses position() = 0 instead of NOT LIKE '%val%'.
     *
     * @param  array<mixed>  $values
     */
    #[\Override]
    protected function compileNotContains(string $attribute, array $values, ?string $column = null): string
    {
        /** @var array<string> $values */
        if (\count($values) === 1) {
            $this->addBinding($values[0], $column);

            return 'position(' . $attribute . ', ?) = 0';
        }

        $parts = [];
        foreach ($values as $value) {
            $this->addBinding($value, $column);
            $parts[] = 'position(' . $attribute . ', ?) = 0';
        }

        return '(' . \implode(' AND ', $parts) . ')';
    }

    #[\Override]
    public function build(): Statement
    {
        return $this->applyNamedTypedBindings(parent::build());
    }

    #[\Override]
    public function insert(): Statement
    {
        $format = $this->insertFormat;
        if ($format === null) {
            return $this->applyNamedTypedBindings(parent::insert());
        }

        $columns = !empty($this->insertFormatColumns)
            ? $this->insertFormatColumns
            : (!empty($this->rows) ? \array_keys($this->rows[0]) : []);

        $sql = $this->compileFormatInsertEnvelope($format, $columns);

        return new FormattedInsertStatement(
            $sql,
            [],
            $columns,
            $format,
            executor: $this->executor,
        );
    }

    #[\Override]
    public function update(): Statement
    {
        $this->bindings = [];
        $this->validateTable();

        $assignments = $this->compileAssignments();

        if (empty($assignments)) {
            throw new ValidationException('No assignments for UPDATE. Call set() or setRaw() before update().');
        }

        $parts = [];

        $this->compileWhereClauses($parts);

        if (empty($parts)) {
            throw new ValidationException('ClickHouse UPDATE requires a WHERE clause.');
        }

        $sql = 'ALTER TABLE ' . $this->quote($this->table)
            . ' UPDATE ' . \implode(', ', $assignments)
            . ' ' . \implode(' ', $parts);

        return $this->applyNamedTypedBindings(
            new Statement($sql, $this->getBindingValues(), executor: $this->executor)
        );
    }

    /**
     * Pick which DELETE form `delete()` emits. Lightweight (`DELETE FROM
     * t WHERE …`) marks rows deleted via a mask and is async by default;
     * mutation (`ALTER TABLE t DELETE WHERE …`) rewrites parts on disk
     * and is heavier. The choice is storage-path-significant: the two
     * forms are not interchangeable, so the builder never auto-translates
     * between them.
     */
    public function deleteMode(string $mode): static
    {
        if ($mode !== self::DELETE_MODE_LIGHTWEIGHT && $mode !== self::DELETE_MODE_MUTATION) {
            throw new ValidationException(
                'Invalid ClickHouse delete mode: ' . $mode
                . '. Allowed: ' . self::DELETE_MODE_LIGHTWEIGHT . ', ' . self::DELETE_MODE_MUTATION
            );
        }

        $this->deleteMode = $mode;

        return $this;
    }

    #[\Override]
    public function delete(): Statement
    {
        $this->bindings = [];
        $this->validateTable();

        $parts = [];

        $this->compileWhereClauses($parts);

        if (empty($parts)) {
            throw new ValidationException('ClickHouse DELETE requires a WHERE clause.');
        }

        $sql = $this->deleteMode === self::DELETE_MODE_LIGHTWEIGHT
            ? 'DELETE FROM ' . $this->quote($this->table) . ' ' . \implode(' ', $parts)
            : 'ALTER TABLE ' . $this->quote($this->table) . ' DELETE ' . \implode(' ', $parts);

        $settings = $this->buildSettingsClause();
        if ($settings !== '') {
            $sql .= ' ' . $settings;
        }

        return $this->applyNamedTypedBindings(
            new Statement($sql, $this->getBindingValues(), executor: $this->executor)
        );
    }

    /**
     * ClickHouse does not support subqueries in JOIN ON conditions.
     * Force all join filter conditions to WHERE placement.
     */
    #[\Override]
    protected function resolveJoinFilterPlacement(Placement $requested, bool $isCrossJoin): Placement
    {
        return Placement::Where;
    }

    #[\Override]
    protected function buildTableClause(): string
    {
        $fromSub = $this->fromSubquery;
        if ($fromSub !== null) {
            $subResult = $fromSub->subquery->build();
            $this->addBindings($subResult->bindings);

            return 'FROM (' . $subResult->query . ') AS ' . $this->quote($fromSub->alias);
        }

        $sql = 'FROM ' . $this->quote($this->table);

        if ($this->useFinal) {
            $sql .= ' FINAL';
        }

        if ($this->sampleFraction !== null) {
            $sql .= ' SAMPLE ' . \sprintf('%.10g', $this->sampleFraction);
        }

        if ($this->alias !== '') {
            $sql .= ' AS ' . $this->quote($this->alias);
        }

        return $sql;
    }

    /**
     * Emit PREWHERE (before reading all columns), ARRAY JOIN, and raw ASOF
     * joins between the JOIN section and WHERE. These are structural
     * ClickHouse clauses that do not carry bindings.
     */
    #[\Override]
    protected function buildAfterJoinsClause(ParsedQuery $grouped): string
    {
        $parts = [];

        if (! empty($this->arrayJoins)) {
            $arrayJoinParts = [];
            foreach ($this->arrayJoins as $aj) {
                $clause = $aj['type'] . ' ' . $this->resolveAndWrap($aj['column']);
                if ($aj['alias'] !== '') {
                    $clause .= ' AS ' . $this->quote($aj['alias']);
                }
                $arrayJoinParts[] = $clause;
            }
            $parts[] = \implode(' ', $arrayJoinParts);
        }

        if (! empty($this->rawJoinClauses)) {
            $parts[] = \implode(' ', $this->rawJoinClauses);
        }

        if (! empty($this->prewhereQueries)) {
            $clauses = [];
            foreach ($this->prewhereQueries as $query) {
                $clauses[] = $this->compileFilter($query);
            }
            $parts[] = 'PREWHERE ' . \implode(' AND ', $clauses);
        }

        return \implode(' ', $parts);
    }

    /**
     * Emit the ClickHouse GROUP BY modifier (WITH TOTALS / WITH ROLLUP /
     * WITH CUBE) between GROUP BY and HAVING.
     */
    #[\Override]
    protected function buildAfterGroupByClause(): string
    {
        return $this->groupByModifier ?? '';
    }

    /**
     * Emit LIMIT BY between ORDER BY and LIMIT. The count binding is added
     * here so ordering is naturally correct: LIMIT BY binding precedes the
     * outer LIMIT binding emitted by the parent.
     */
    #[\Override]
    protected function buildAfterOrderByClause(): string
    {
        if ($this->limitByClause === null) {
            return '';
        }

        $cols = \array_map(
            fn (string $col): string => $this->resolveAndWrap($col),
            $this->limitByClause['columns']
        );

        $this->addBinding($this->limitByClause['count']);

        return 'LIMIT ? BY ' . \implode(', ', $cols);
    }

    /**
     * Emit the trailing SETTINGS fragment from registered hints.
     */
    #[\Override]
    protected function buildSettingsClause(): string
    {
        if (empty($this->hints)) {
            return '';
        }

        return 'SETTINGS ' . \implode(', ', $this->hints);
    }
}
