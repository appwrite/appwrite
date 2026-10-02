<?php

namespace Utopia\Query\Builder;

use Utopia\Query\AST\Serializer;
use Utopia\Query\AST\Serializer\PostgreSQL as PostgreSQLSerializer;
use Utopia\Query\Builder\Feature\ConditionalAggregates;
use Utopia\Query\Builder\Feature\Cube;
use Utopia\Query\Builder\Feature\FullOuterJoins;
use Utopia\Query\Builder\Feature\FullTextSearch;
use Utopia\Query\Builder\Feature\InsertOrIgnore;
use Utopia\Query\Builder\Feature\Json;
use Utopia\Query\Builder\Feature\LateralJoins;
use Utopia\Query\Builder\Feature\NegatedFullTextSearch;
use Utopia\Query\Builder\Feature\PostgreSQL\AggregateFilter;
use Utopia\Query\Builder\Feature\PostgreSQL\DistinctOn;
use Utopia\Query\Builder\Feature\PostgreSQL\LockingOf;
use Utopia\Query\Builder\Feature\PostgreSQL\Merge;
use Utopia\Query\Builder\Feature\PostgreSQL\OrderedSetAggregates;
use Utopia\Query\Builder\Feature\PostgreSQL\Returning;
use Utopia\Query\Builder\Feature\PostgreSQL\VectorSearch;
use Utopia\Query\Builder\Feature\Rollup;
use Utopia\Query\Builder\Feature\Sequences;
use Utopia\Query\Builder\Feature\Spatial;
use Utopia\Query\Builder\Feature\StringAggregates;
use Utopia\Query\Builder\Feature\TableSampling;
use Utopia\Query\Builder\Feature\Upsert;
use Utopia\Query\Builder\Feature\UpsertSelect;
use Utopia\Query\Builder\PostgreSQL\DeleteUsing;
use Utopia\Query\Builder\PostgreSQL\MergeTarget;
use Utopia\Query\Builder\PostgreSQL\UpdateFrom;
use Utopia\Query\Exception\ValidationException;
use Utopia\Query\Method;
use Utopia\Query\Query;
use Utopia\Query\Schema\ColumnType;

class PostgreSQL extends SQL implements
    VectorSearch,
    Json,
    Returning,
    LockingOf,
    ConditionalAggregates,
    Merge,
    LateralJoins,
    TableSampling,
    FullOuterJoins,
    StringAggregates,
    OrderedSetAggregates,
    DistinctOn,
    AggregateFilter,
    Rollup,
    Cube,
    Sequences,
    Spatial,
    FullTextSearch,
    NegatedFullTextSearch,
    Upsert,
    UpsertSelect,
    InsertOrIgnore
{
    use Trait\FullOuterJoins;
    use Trait\FullTextSearch;
    use Trait\NegatedFullTextSearch;
    use Trait\GroupByModifiers;
    use Trait\LateralJoins;
    use Trait\PostgreSQL\AggregateFilter;
    use Trait\PostgreSQL\DistinctOn;
    use Trait\PostgreSQL\LockingOf;
    use Trait\PostgreSQL\Merge;
    use Trait\PostgreSQL\OrderedSetAggregates;
    use Trait\PostgreSQL\Sequences;
    use Trait\PostgreSQL\VectorSearch;
    use Trait\Returning;
    use Trait\Spatial;
    use Trait\StringAggregates;
    use Trait\Upsert {
        upsert as private baseUpsert;
    }
    use Trait\UpsertSelect {
        upsertSelect as private baseUpsertSelect;
    }

    protected string $wrapChar = '"';

    #[\Override]
    protected function createAstSerializer(): Serializer
    {
        return new PostgreSQLSerializer();
    }

    /** @var ?array{attribute: string, vector: array<float>, metric: VectorMetric} */
    protected ?array $vectorOrder = null;

    protected ?UpdateFrom $updateFrom = null;

    protected ?DeleteUsing $deleteUsing = null;

    protected ?MergeTarget $mergeTarget = null;

    /** @var list<MergeClause> */
    protected array $mergeClauses = [];

    /** @var list<string> */
    protected array $distinctOnColumns = [];

    #[\Override]
    protected function compileRandom(): string
    {
        return 'RANDOM()';
    }

    /**
     * @param  array<mixed>  $values
     */
    #[\Override]
    protected function compileRegex(string $attribute, array $values, ?string $column = null): string
    {
        $this->addBinding($values[0], $column);

        return $attribute . ' ~ ?';
    }

    /**
     * @param  array<mixed>  $values
     */
    #[\Override]
    protected function compileSearchExpr(string $attribute, array $values, bool $not): string
    {
        /** @var string $term */
        $term = $values[0] ?? '';
        $exact = \str_ends_with($term, '"') && \str_starts_with($term, '"');

        $specialChars = ['@', '+', '-', '*', '.', "'", '"', ')', '(', '<', '>', '~'];
        $sanitized = \str_replace($specialChars, ' ', $term);
        $sanitized = \preg_replace('/\s+/', ' ', $sanitized) ?? '';
        $sanitized = \trim($sanitized);

        if ($sanitized === '') {
            return $not ? '1 = 1' : '1 = 0';
        }

        if ($exact) {
            $sanitized = '"' . $sanitized . '"';
        } else {
            $sanitized = \str_replace(' ', ' or ', $sanitized);
        }

        $this->addBinding($sanitized);
        $tsvector = "to_tsvector(regexp_replace(" . $attribute . ", '[^\\w]+', ' ', 'g'))";

        if ($not) {
            return 'NOT (' . $tsvector . ' @@ websearch_to_tsquery(?))';
        }

        return $tsvector . ' @@ websearch_to_tsquery(?)';
    }

    #[\Override]
    protected function compileConflictHeader(): string
    {
        $wrappedKeys = \array_map(
            fn (string $key): string => $this->resolveAndWrap($key),
            $this->conflictKeys
        );

        return 'ON CONFLICT (' . \implode(', ', $wrappedKeys) . ') DO UPDATE SET';
    }

    #[\Override]
    protected function compileConflictAssignment(string $wrapped): string
    {
        return 'EXCLUDED.' . $wrapped;
    }

    #[\Override]
    protected function shouldEmitOffset(?int $offset, ?int $limit): bool
    {
        return $offset !== null;
    }

    #[\Override]
    public function tablesample(float $percent, string $method = 'BERNOULLI'): static
    {
        $normalized = \strtoupper($method);
        if (! \in_array($normalized, ['BERNOULLI', 'SYSTEM'], true)) {
            throw new ValidationException('Invalid TABLESAMPLE method: ' . $method);
        }
        $this->sample = ['percent' => $percent, 'method' => $normalized];

        return $this;
    }

    #[\Override]
    public function insertOrIgnore(): Statement
    {
        $this->bindings = [];
        [$sql, $bindings] = $this->compileInsertBody();
        $this->addBindings($bindings);

        $sql .= ' ON CONFLICT DO NOTHING';

        return $this->appendReturning(new Statement($sql, $this->getBindingValues(), executor: $this->executor));
    }

    #[\Override]
    public function insert(): Statement
    {
        $result = parent::insert();

        return $this->appendReturning($result);
    }

    public function updateFrom(string $table, string $alias = ''): static
    {
        $current = $this->updateFrom;
        $this->updateFrom = new UpdateFrom(
            table: $table,
            alias: $alias,
            condition: $current === null ? '' : $current->condition,
            bindings: $current === null ? [] : $current->bindings,
        );

        return $this;
    }

    public function updateFromWhere(string $condition, mixed ...$bindings): static
    {
        $current = $this->updateFrom;
        $this->updateFrom = new UpdateFrom(
            table: $current === null ? '' : $current->table,
            alias: $current === null ? '' : $current->alias,
            condition: $condition,
            bindings: \array_values($bindings),
        );

        return $this;
    }

    #[\Override]
    public function update(): Statement
    {
        foreach ($this->jsonSets as $col => $condition) {
            $this->setRaw($col, $condition->expression, $condition->bindings);
        }

        if ($this->updateFrom !== null && $this->updateFrom->table !== '') {
            $result = $this->buildUpdateFrom();
            $this->jsonSets = [];

            return $this->appendReturning($result);
        }

        $result = parent::update();
        $this->jsonSets = [];

        return $this->appendReturning($result);
    }

    private function buildUpdateFrom(): Statement
    {
        $this->bindings = [];
        $this->validateTable();

        $updateFrom = $this->updateFrom;
        if ($updateFrom === null) {
            throw new ValidationException('No UPDATE FROM target specified.');
        }

        $assignments = $this->compileAssignments();

        if (empty($assignments)) {
            throw new ValidationException('No assignments for UPDATE. Call set() or setRaw() before update().');
        }

        $fromClause = $this->quote($updateFrom->table);
        if ($updateFrom->alias !== '') {
            $fromClause .= ' AS ' . $this->quote($updateFrom->alias);
        }

        $sql = 'UPDATE ' . $this->quote($this->table)
            . ' SET ' . \implode(', ', $assignments)
            . ' FROM ' . $fromClause;

        $parts = [$sql];

        $extraWhere = [];
        if ($updateFrom->condition !== '') {
            $extraWhere[] = $updateFrom->condition;
            foreach ($updateFrom->bindings as $binding) {
                $this->addBinding($binding);
            }
        }

        $this->compileWhereClauses($parts);
        $this->mergeIntoWhereClause($parts, $extraWhere);

        return new Statement(\implode(' ', $parts), $this->getBindingValues(), executor: $this->executor);
    }

    public function deleteUsing(string $table, string $condition, mixed ...$bindings): static
    {
        $this->deleteUsing = new DeleteUsing(
            table: $table,
            condition: $condition,
            bindings: \array_values($bindings),
        );

        return $this;
    }

    #[\Override]
    public function delete(): Statement
    {
        if ($this->deleteUsing !== null && $this->deleteUsing->table !== '') {
            $result = $this->buildDeleteUsing();

            return $this->appendReturning($result);
        }

        $result = parent::delete();

        return $this->appendReturning($result);
    }

    private function buildDeleteUsing(): Statement
    {
        $this->bindings = [];
        $this->validateTable();

        $deleteUsing = $this->deleteUsing;
        if ($deleteUsing === null) {
            throw new ValidationException('No DELETE USING target specified.');
        }

        $sql = 'DELETE FROM ' . $this->quote($this->table)
            . ' USING ' . $this->quote($deleteUsing->table);

        $parts = [$sql];

        $extraWhere = [];
        if ($deleteUsing->condition !== '') {
            $extraWhere[] = $deleteUsing->condition;
            foreach ($deleteUsing->bindings as $binding) {
                $this->addBinding($binding);
            }
        }

        $this->compileWhereClauses($parts);
        $this->mergeIntoWhereClause($parts, $extraWhere);

        return new Statement(\implode(' ', $parts), $this->getBindingValues(), executor: $this->executor);
    }

    /**
     * Merge additional conditions into the trailing WHERE clause in $parts.
     * If the last part already begins with "WHERE ", append with AND; otherwise
     * push a new WHERE fragment. No-op when $extra is empty.
     *
     * @param  array<string>  $parts
     * @param  list<string>   $extra
     */
    private function mergeIntoWhereClause(array &$parts, array $extra): void
    {
        if (empty($extra)) {
            return;
        }

        $lastPart = \end($parts);
        if (\is_string($lastPart) && \str_starts_with($lastPart, 'WHERE ')) {
            $parts[\count($parts) - 1] = $lastPart . ' AND ' . \implode(' AND ', $extra);

            return;
        }

        $parts[] = 'WHERE ' . \implode(' AND ', $extra);
    }

    public function upsert(): Statement
    {
        return $this->appendReturning($this->baseUpsert());
    }

    public function upsertSelect(): Statement
    {
        return $this->appendReturning($this->baseUpsertSelect());
    }

    #[\Override]
    public function setJsonAppend(string $column, array $values): static
    {
        $this->jsonSets[$column] = new Condition(
            'COALESCE(' . $this->resolveAndWrap($column) . ', \'[]\'::jsonb) || ?::jsonb',
            [\json_encode($values)],
        );

        return $this;
    }

    #[\Override]
    public function setJsonPrepend(string $column, array $values): static
    {
        $this->jsonSets[$column] = new Condition(
            '?::jsonb || COALESCE(' . $this->resolveAndWrap($column) . ', \'[]\'::jsonb)',
            [\json_encode($values)],
        );

        return $this;
    }

    #[\Override]
    public function setJsonInsert(string $column, int $index, mixed $value): static
    {
        $this->jsonSets[$column] = new Condition(
            'jsonb_insert(' . $this->resolveAndWrap($column) . ', \'{' . $index . '}\', ?::jsonb)',
            [\json_encode($value)],
        );

        return $this;
    }

    #[\Override]
    public function setJsonRemove(string $column, mixed $value): static
    {
        $this->jsonSets[$column] = new Condition(
            $this->resolveAndWrap($column) . ' - ?',
            [\json_encode($value)],
        );

        return $this;
    }

    #[\Override]
    public function setJsonIntersect(string $column, array $values): static
    {
        $this->setRaw($column, '(SELECT jsonb_agg(elem) FROM jsonb_array_elements(' . $this->resolveAndWrap($column) . ') AS elem WHERE elem <@ ?::jsonb)', [\json_encode($values)]);

        return $this;
    }

    #[\Override]
    public function setJsonDiff(string $column, array $values): static
    {
        $this->setRaw($column, '(SELECT COALESCE(jsonb_agg(elem), \'[]\'::jsonb) FROM jsonb_array_elements(' . $this->resolveAndWrap($column) . ') AS elem WHERE NOT elem <@ ?::jsonb)', [\json_encode($values)]);

        return $this;
    }

    #[\Override]
    public function setJsonUnique(string $column): static
    {
        $this->setRaw($column, '(SELECT jsonb_agg(DISTINCT elem) FROM jsonb_array_elements(' . $this->resolveAndWrap($column) . ') AS elem)');

        return $this;
    }

    #[\Override]
    public function setJsonPath(string $column, string $path, mixed $value): static
    {
        if (! \str_starts_with($path, '$')) {
            throw new ValidationException('JSON path must start with \'$\': ' . $path);
        }

        $trimmed = \ltrim(\substr($path, 1), '.');

        if ($trimmed === '') {
            throw new ValidationException('JSON path must reference at least one key: ' . $path);
        }

        $segments = \explode('.', $trimmed);
        foreach ($segments as $segment) {
            if ($segment === '') {
                throw new ValidationException('JSON path contains an empty segment: ' . $path);
            }
        }

        $pathArray = '{' . \implode(',', $segments) . '}';

        $this->jsonSets[$column] = new Condition(
            'jsonb_set(' . $this->resolveAndWrap($column) . ', ?, to_jsonb(?::text)::jsonb, true)',
            [$pathArray, $value],
        );

        return $this;
    }

    #[\Override]
    public function explain(bool $analyze = false, bool $verbose = false, bool $buffers = false, string $format = ''): Statement
    {
        $normalizedFormat = \strtoupper($format);
        if (! \in_array($normalizedFormat, ['', 'TEXT', 'XML', 'JSON', 'YAML'], true)) {
            throw new ValidationException('Invalid EXPLAIN format: ' . $format);
        }
        $result = $this->build();
        $options = [];
        if ($analyze) {
            $options[] = 'ANALYZE';
        }
        if ($verbose) {
            $options[] = 'VERBOSE';
        }
        if ($buffers) {
            $options[] = 'BUFFERS';
        }
        if ($normalizedFormat !== '') {
            $options[] = 'FORMAT ' . $normalizedFormat;
        }
        $prefix = empty($options) ? 'EXPLAIN' : 'EXPLAIN (' . \implode(', ', $options) . ')';

        return new Statement($prefix . ' ' . $result->query, $result->bindings, readOnly: true, executor: $this->executor);
    }

    #[\Override]
    public function compileFilter(Query $query): string
    {
        $method = $query->getMethod();

        if ($method->isVector()) {
            $attribute = $this->resolveAndWrap($query->getAttribute());

            return $this->compileVectorFilter($method, $attribute, $query);
        }

        if ($query->getAttributeType() === ColumnType::Object->value) {
            return $this->compileObjectFilter($query);
        }

        return parent::compileFilter($query);
    }

    protected function compileObjectFilter(Query $query): string
    {
        $method = $query->getMethod();
        $rawAttr = $query->getAttribute();
        $isNested = \str_contains($rawAttr, '.');

        if ($isNested) {
            $attribute = $this->buildJsonbPath($rawAttr);

            return match ($method) {
                Method::Equal => $this->compileIn($attribute, $query->getValues()),
                Method::NotEqual => $this->compileNotIn($attribute, $query->getValues()),
                Method::LessThan => $this->compileComparison($attribute, '<', $query->getValues()),
                Method::LessThanEqual => $this->compileComparison($attribute, '<=', $query->getValues()),
                Method::GreaterThan => $this->compileComparison($attribute, '>', $query->getValues()),
                Method::GreaterThanEqual => $this->compileComparison($attribute, '>=', $query->getValues()),
                Method::StartsWith => $this->compileLike($attribute, $query->getValues(), '', '%', false),
                Method::NotStartsWith => $this->compileLike($attribute, $query->getValues(), '', '%', true),
                Method::EndsWith => $this->compileLike($attribute, $query->getValues(), '%', '', false),
                Method::NotEndsWith => $this->compileLike($attribute, $query->getValues(), '%', '', true),
                Method::Contains => $this->compileLike($attribute, $query->getValues(), '%', '%', false),
                Method::NotContains => $this->compileLike($attribute, $query->getValues(), '%', '%', true),
                Method::IsNull => $attribute . ' IS NULL',
                Method::IsNotNull => $attribute . ' IS NOT NULL',
                default => parent::compileFilter($query),
            };
        }

        $attribute = $this->resolveAndWrap($rawAttr);

        return match ($method) {
            Method::Equal, Method::NotEqual => $this->compileJsonbContainment($attribute, $query->getValues(), $method === Method::NotEqual, false),
            Method::Contains, Method::ContainsAny, Method::ContainsAll, Method::NotContains => $this->compileJsonbContainment($attribute, $query->getValues(), $method === Method::NotContains, true),
            Method::StartsWith => $this->compileLike($attribute . '::text', $query->getValues(), '', '%', false),
            Method::NotStartsWith => $this->compileLike($attribute . '::text', $query->getValues(), '', '%', true),
            Method::EndsWith => $this->compileLike($attribute . '::text', $query->getValues(), '%', '', false),
            Method::NotEndsWith => $this->compileLike($attribute . '::text', $query->getValues(), '%', '', true),
            Method::IsNull => $attribute . ' IS NULL',
            Method::IsNotNull => $attribute . ' IS NOT NULL',
            default => parent::compileFilter($query),
        };
    }

    /**
     * @param array<mixed> $values
     */
    protected function compileJsonbContainment(string $attribute, array $values, bool $not, bool $wrapScalars): string
    {
        $conditions = [];
        foreach ($values as $value) {
            if ($wrapScalars && \is_array($value) && \count($value) === 1) {
                $jsonKey = \array_key_first($value);
                $jsonValue = $value[$jsonKey];
                if (!\is_array($jsonValue)) {
                    $value[$jsonKey] = [$jsonValue];
                }
            }
            $this->addBinding(\json_encode($value));
            $fragment = $attribute . ' @> ?::jsonb';
            $conditions[] = $not ? 'NOT (' . $fragment . ')' : $fragment;
        }
        $separator = $not ? ' AND ' : ' OR ';

        return '(' . \implode($separator, $conditions) . ')';
    }

    #[\Override]
    protected function getLikeKeyword(): string
    {
        return 'ILIKE';
    }

    protected function buildJsonbPath(string $path): string
    {
        $parts = \explode('.', $path);
        if (\count($parts) === 1) {
            return $this->resolveAndWrap($parts[0]);
        }

        $base = $this->quote($this->resolveAttribute($parts[0]));
        $lastKey = \array_pop($parts);
        \array_shift($parts);

        $chain = $base;
        foreach ($parts as $key) {
            $chain .= "->'" . $key . "'";
        }

        return $chain . "->>'" . $lastKey . "'";
    }

    #[\Override]
    protected function compileVectorOrderExpr(): ?Condition
    {
        if ($this->vectorOrder === null) {
            return null;
        }

        $attr = $this->resolveAndWrap($this->vectorOrder['attribute']);
        $operator = $this->vectorOrder['metric']->toOperator();
        $vectorJson = \json_encode($this->vectorOrder['vector']);

        return new Condition(
            '(' . $attr . ' ' . $operator . ' ?::vector) ASC',
            [$vectorJson],
        );
    }

    #[\Override]
    public function countWhen(string $condition, string $alias = '', mixed ...$bindings): static
    {
        return $this->aggregateFilter('COUNT', null, $condition, $alias, \array_values($bindings));
    }

    #[\Override]
    public function sumWhen(string $column, string $condition, string $alias = '', mixed ...$bindings): static
    {
        return $this->aggregateFilter('SUM', $column, $condition, $alias, \array_values($bindings));
    }

    #[\Override]
    public function avgWhen(string $column, string $condition, string $alias = '', mixed ...$bindings): static
    {
        return $this->aggregateFilter('AVG', $column, $condition, $alias, \array_values($bindings));
    }

    #[\Override]
    public function minWhen(string $column, string $condition, string $alias = '', mixed ...$bindings): static
    {
        return $this->aggregateFilter('MIN', $column, $condition, $alias, \array_values($bindings));
    }

    #[\Override]
    public function maxWhen(string $column, string $condition, string $alias = '', mixed ...$bindings): static
    {
        return $this->aggregateFilter('MAX', $column, $condition, $alias, \array_values($bindings));
    }

    /**
     * Emit a conditional aggregate using PostgreSQL's FILTER (WHERE ...) clause.
     *
     * @param  list<mixed>  $bindings
     */
    private function aggregateFilter(string $aggregate, ?string $column, string $condition, string $alias, array $bindings): static
    {
        $argument = $column === null ? '*' : $this->resolveAndWrap($column);
        $expr = $aggregate . '(' . $argument . ') FILTER (WHERE ' . $condition . ')';
        if ($alias !== '') {
            $expr .= ' AS ' . $this->quote($alias);
        }

        return $this->select($expr, $bindings);
    }

    #[\Override]
    protected function groupConcatExpr(string $column, string $orderBy): string
    {
        $suffix = $orderBy === '' ? '' : ' ' . $orderBy;

        return 'STRING_AGG(' . $column . ', ?' . $suffix . ')';
    }

    #[\Override]
    protected function jsonArrayAggExpr(string $column): string
    {
        return 'JSON_AGG(' . $column . ')';
    }

    #[\Override]
    protected function jsonObjectAggExpr(string $keyColumn, string $valueColumn): string
    {
        return 'JSON_OBJECT_AGG(' . $keyColumn . ', ' . $valueColumn . ')';
    }

    #[\Override]
    public function insertDefaultValues(): Statement
    {
        $result = parent::insertDefaultValues();

        return $this->appendReturning($result);
    }

    #[\Override]
    public function withRollup(): static
    {
        $this->groupByModifier = 'ROLLUP';

        return $this;
    }

    #[\Override]
    public function withCube(): static
    {
        $this->groupByModifier = 'CUBE';

        return $this;
    }

    #[\Override]
    public function build(): Statement
    {
        $result = parent::build();
        $query = $result->query;
        $modified = false;

        if (! empty($this->distinctOnColumns)) {
            $cols = \array_map(
                fn (string $col): string => $this->resolveAndWrap($col),
                $this->distinctOnColumns
            );
            $distinctOnClause = 'SELECT DISTINCT ON (' . \implode(', ', $cols) . ')';
            $query = \preg_replace('/^SELECT(\s+DISTINCT)?/', $distinctOnClause, $query, 1) ?? $query;
            $modified = true;
        }

        if ($this->groupByModifier !== null) {
            $groupByPos = \strpos($query, 'GROUP BY ');
            if ($groupByPos !== false) {
                $afterGroupBy = $groupByPos + 9;
                $endPos = null;
                foreach (['HAVING ', 'WINDOW ', 'ORDER BY ', 'LIMIT ', 'OFFSET ', 'FETCH ', 'FOR '] as $keyword) {
                    $pos = \strpos($query, $keyword, $afterGroupBy);
                    if ($pos !== false && ($endPos === null || $pos < $endPos)) {
                        $endPos = $pos;
                    }
                }
                $columns = $endPos !== null
                    ? \rtrim(\substr($query, $afterGroupBy, $endPos - $afterGroupBy))
                    : \substr($query, $afterGroupBy);
                $replacement = 'GROUP BY ' . $this->groupByModifier . '(' . $columns . ')';
                $query = \substr($query, 0, $groupByPos) . $replacement . ($endPos !== null ? ' ' . \substr($query, $endPos) : '');
            }
            $modified = true;
        }

        if ($modified) {
            return new Statement($query, $result->bindings, $result->readOnly, $this->executor);
        }

        return $result;
    }

    #[\Override]
    public function reset(): static
    {
        parent::reset();
        $this->jsonSets = [];
        $this->vectorOrder = null;
        $this->resetReturning();
        $this->updateFrom = null;
        $this->deleteUsing = null;
        $this->mergeTarget = null;
        $this->mergeClauses = [];
        $this->distinctOnColumns = [];
        $this->resetGroupByModifier();

        return $this;
    }

    /**
     * @param  array<mixed>  $values
     */
    #[\Override]
    protected function compileSpatialDistance(Method $method, string $attribute, array $values): string
    {
        /** @var array{0: string|array<mixed>, 1: float, 2: bool} $tuple */
        $tuple = $values[0];
        $filter = SpatialDistanceFilter::fromTuple($tuple);
        $wkt = \is_array($filter->geometry) ? $this->geometryToWkt($filter->geometry) : $filter->geometry;

        $operator = match ($method) {
            Method::DistanceLessThan => '<',
            Method::DistanceGreaterThan => '>',
            Method::DistanceEqual => '=',
            Method::DistanceNotEqual => '!=',
            default => '<',
        };

        $this->addBinding($wkt);
        $this->addBinding($filter->distance);

        if ($filter->meters) {
            return 'ST_Distance((' . $attribute . '::geography), ST_SetSRID(ST_GeomFromText(?), 4326)::geography) ' . $operator . ' ?';
        }

        return 'ST_Distance(' . $attribute . ', ST_GeomFromText(?, 4326)) ' . $operator . ' ?';
    }

    /**
     * @param  array<mixed>  $values
     */
    #[\Override]
    protected function compileSpatialPredicate(string $function, string $attribute, array $values, bool $not): string
    {
        /** @var array<mixed> $geometry */
        $geometry = $values[0];
        $wkt = $this->geometryToWkt($geometry);
        $this->addBinding($wkt);

        $expr = $function . '(' . $attribute . ', ST_GeomFromText(?, 4326))';

        return $not ? 'NOT ' . $expr : $expr;
    }

    /**
     * @param  array<mixed>  $values
     */
    #[\Override]
    protected function compileSpatialCoversPredicate(string $attribute, array $values, bool $not): string
    {
        return $this->compileSpatialPredicate('ST_Covers', $attribute, $values, $not);
    }

    /**
     * @param  array<mixed>  $values
     */
    #[\Override]
    protected function compileJsonContainsExpr(string $attribute, array $values, bool $not): string
    {
        $this->addBinding(\json_encode($values[0]));
        $expr = $attribute . ' @> ?::jsonb';

        return $not ? 'NOT (' . $expr . ')' : $expr;
    }

    /**
     * @param  array<mixed>  $values
     */
    #[\Override]
    protected function compileJsonOverlapsExpr(string $attribute, array $values): string
    {
        /** @var array<mixed> $arr */
        $arr = $values[0];

        $conditions = [];
        foreach ($arr as $value) {
            $this->addBinding(\json_encode($value));
            $conditions[] = $attribute . ' @> ?::jsonb';
        }

        return '(' . \implode(' OR ', $conditions) . ')';
    }

    /**
     * @param  array<mixed>  $values
     */
    #[\Override]
    protected function compileJsonPathExpr(string $attribute, array $values): string
    {
        /** @var string $path */
        $path = $values[0];
        /** @var string $operator */
        $operator = $values[1];
        $value = $values[2];

        if (!\preg_match('/^[a-zA-Z0-9_.\[\]]+$/', $path)) {
            throw new ValidationException('Invalid JSON path: ' . $path);
        }

        $allowedOperators = ['=', '!=', '<', '>', '<=', '>=', '<>'];
        if (!\in_array($operator, $allowedOperators, true)) {
            throw new ValidationException('Invalid JSON path operator: ' . $operator);
        }

        $this->addBinding($value);

        return $attribute . '->>\''. $path . '\' ' . $operator . ' ?';
    }

    private function compileVectorFilter(Method $method, string $attribute, Query $query): string
    {
        $values = $query->getValues();
        /** @var array<float> $vector */
        $vector = $values[0];

        $operator = match ($method) {
            Method::VectorCosine => '<=>',
            Method::VectorEuclidean => '<->',
            Method::VectorDot => '<#>',
            default => '<=>',
        };

        $this->addBinding(\json_encode($vector));

        return '(' . $attribute . ' ' . $operator . ' ?::vector)';
    }

}
