<?php

namespace Utopia\Query\Builder;

use Utopia\Query\Builder\Feature\ConditionalAggregates;
use Utopia\Query\Builder\Feature\FullTextSearch;
use Utopia\Query\Builder\Feature\Hints;
use Utopia\Query\Builder\Feature\InsertOrIgnore;
use Utopia\Query\Builder\Feature\Json;
use Utopia\Query\Builder\Feature\LateralJoins;
use Utopia\Query\Builder\Feature\NegatedFullTextSearch;
use Utopia\Query\Builder\Feature\Rollup;
use Utopia\Query\Builder\Feature\Spatial;
use Utopia\Query\Builder\Feature\StringAggregates;
use Utopia\Query\Builder\Feature\Upsert;
use Utopia\Query\Builder\Feature\UpsertSelect;
use Utopia\Query\Exception\ValidationException;
use Utopia\Query\Method;

class MySQL extends SQL implements
    Json,
    Hints,
    ConditionalAggregates,
    LateralJoins,
    StringAggregates,
    Rollup,
    Spatial,
    FullTextSearch,
    NegatedFullTextSearch,
    Upsert,
    UpsertSelect,
    InsertOrIgnore
{
    use Trait\ConditionalAggregates;
    use Trait\FullTextSearch;
    use Trait\NegatedFullTextSearch;
    use Trait\GroupByModifiers;
    use Trait\Hints;
    use Trait\LateralJoins;
    use Trait\Spatial;
    use Trait\StringAggregates;
    use Trait\Upsert;
    use Trait\UpsertSelect;

    protected string $updateJoinTable = '';

    protected string $updateJoinLeft = '';

    protected string $updateJoinRight = '';

    protected string $updateJoinAlias = '';

    protected string $deleteAlias = '';

    protected string $deleteJoinTable = '';

    protected string $deleteJoinLeft = '';

    protected string $deleteJoinRight = '';

    #[\Override]
    protected function compileRandom(): string
    {
        return 'RAND()';
    }

    /**
     * @param  array<mixed>  $values
     */
    #[\Override]
    protected function compileRegex(string $attribute, array $values, ?string $column = null): string
    {
        $this->addBinding($values[0], $column);

        return $attribute . ' REGEXP ?';
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

        $specialChars = '@,+,-,*,),(,<,>,~,"';
        $sanitized = \str_replace(\explode(',', $specialChars), ' ', $term);
        $sanitized = \preg_replace('/\s+/', ' ', $sanitized) ?? '';
        $sanitized = \trim($sanitized);

        if ($sanitized === '') {
            return $not ? '1 = 1' : '1 = 0';
        }

        if ($exact) {
            $sanitized = '"' . $sanitized . '"';
        } else {
            $sanitized .= '*';
        }

        $this->addBinding($sanitized);

        if ($not) {
            return 'NOT (MATCH(' . $attribute . ') AGAINST(? IN BOOLEAN MODE))';
        }

        return 'MATCH(' . $attribute . ') AGAINST(? IN BOOLEAN MODE)';
    }

    #[\Override]
    protected function compileConflictHeader(): string
    {
        return 'ON DUPLICATE KEY UPDATE';
    }

    #[\Override]
    protected function compileConflictAssignment(string $wrapped): string
    {
        return 'VALUES(' . $wrapped . ')';
    }

    #[\Override]
    public function setJsonAppend(string $column, array $values): static
    {
        $this->jsonSets[$column] = new Condition(
            'JSON_MERGE_PRESERVE(IFNULL(' . $this->resolveAndWrap($column) . ', JSON_ARRAY()), ?)',
            [\json_encode($values)],
        );

        return $this;
    }

    #[\Override]
    public function setJsonPrepend(string $column, array $values): static
    {
        $this->jsonSets[$column] = new Condition(
            'JSON_MERGE_PRESERVE(?, IFNULL(' . $this->resolveAndWrap($column) . ', JSON_ARRAY()))',
            [\json_encode($values)],
        );

        return $this;
    }

    #[\Override]
    public function setJsonInsert(string $column, int $index, mixed $value): static
    {
        $this->jsonSets[$column] = new Condition(
            'JSON_ARRAY_INSERT(' . $this->resolveAndWrap($column) . ', ?, ?)',
            ['$[' . $index . ']', $value],
        );

        return $this;
    }

    #[\Override]
    public function setJsonRemove(string $column, mixed $value): static
    {
        $this->jsonSets[$column] = new Condition(
            'JSON_REMOVE(' . $this->resolveAndWrap($column) . ', JSON_UNQUOTE(JSON_SEARCH(' . $this->resolveAndWrap($column) . ', \'one\', ?)))',
            [$value],
        );

        return $this;
    }

    #[\Override]
    public function setJsonIntersect(string $column, array $values): static
    {
        $this->setRaw($column, '(SELECT JSON_ARRAYAGG(val) FROM JSON_TABLE(' . $this->resolveAndWrap($column) . ', \'$[*]\' COLUMNS(val JSON PATH \'$\')) AS jt WHERE JSON_CONTAINS(?, val))', [\json_encode($values)]);

        return $this;
    }

    #[\Override]
    public function setJsonDiff(string $column, array $values): static
    {
        $this->setRaw($column, '(SELECT JSON_ARRAYAGG(val) FROM JSON_TABLE(' . $this->resolveAndWrap($column) . ', \'$[*]\' COLUMNS(val JSON PATH \'$\')) AS jt WHERE NOT JSON_CONTAINS(?, val))', [\json_encode($values)]);

        return $this;
    }

    #[\Override]
    public function setJsonUnique(string $column): static
    {
        $this->setRaw($column, '(SELECT JSON_ARRAYAGG(val) FROM (SELECT DISTINCT val FROM JSON_TABLE(' . $this->resolveAndWrap($column) . ', \'$[*]\' COLUMNS(val JSON PATH \'$\')) AS jt) AS dt)');

        return $this;
    }

    #[\Override]
    public function setJsonPath(string $column, string $path, mixed $value): static
    {
        if (! \str_starts_with($path, '$')) {
            throw new ValidationException('JSON path must start with \'$\': ' . $path);
        }

        $this->jsonSets[$column] = new Condition(
            'JSON_SET(' . $this->resolveAndWrap($column) . ', ?, ?)',
            [$path, $value],
        );

        return $this;
    }

    public function maxExecutionTime(int $ms): static
    {
        return $this->hint("MAX_EXECUTION_TIME({$ms})");
    }

    #[\Override]
    public function insertOrIgnore(): Statement
    {
        $this->bindings = [];
        [$sql, $bindings] = $this->compileInsertBody();
        $this->addBindings($bindings);

        // Replace "INSERT INTO" with "INSERT IGNORE INTO"
        $sql = \preg_replace('/^INSERT INTO/', 'INSERT IGNORE INTO', $sql, 1) ?? $sql;

        return new Statement($sql, $this->getBindingValues(), executor: $this->executor);
    }

    #[\Override]
    public function explain(bool $analyze = false, string $format = ''): Statement
    {
        $result = $this->build();
        $prefix = 'EXPLAIN';
        if ($analyze) {
            $prefix .= ' ANALYZE';
        }
        if ($format !== '') {
            $prefix .= ' FORMAT=' . \strtoupper($format);
        }

        return new Statement($prefix . ' ' . $result->query, $result->bindings, readOnly: true, executor: $this->executor);
    }

    #[\Override]
    public function build(): Statement
    {
        $result = parent::build();
        $query = $result->query;

        if ($this->groupByModifier !== null) {
            $groupByPos = \strpos($query, 'GROUP BY ');
            if ($groupByPos !== false) {
                $afterGroupBy = $groupByPos + 9;
                $endPos = null;
                foreach (['HAVING ', 'WINDOW ', 'ORDER BY ', 'LIMIT ', 'FOR '] as $keyword) {
                    $pos = \strpos($query, $keyword, $afterGroupBy);
                    if ($pos !== false && ($endPos === null || $pos < $endPos)) {
                        $endPos = $pos;
                    }
                }
                $insertAt = $endPos !== null ? $endPos : \strlen($query);
                $query = \rtrim(\substr($query, 0, $insertAt)) . ' ' . $this->groupByModifier . ($endPos !== null ? ' ' . \substr($query, $endPos) : '');
            }
        }

        if (! empty($this->hints)) {
            $hintStr = '/*+ ' . \implode(' ', $this->hints) . ' */';
            $query = \preg_replace('/^SELECT(\s+DISTINCT)?/', 'SELECT$1 ' . $hintStr, $query, 1) ?? $query;
        }

        if ($query !== $result->query) {
            return new Statement($query, $result->bindings, $result->readOnly, $this->executor);
        }

        return $result;
    }

    public function updateJoin(string $table, string $left, string $right, string $alias = ''): static
    {
        $this->updateJoinTable = $table;
        $this->updateJoinLeft = $left;
        $this->updateJoinRight = $right;
        $this->updateJoinAlias = $alias;

        return $this;
    }

    #[\Override]
    public function update(): Statement
    {
        foreach ($this->jsonSets as $col => $condition) {
            $this->setRaw($col, $condition->expression, $condition->bindings);
        }

        if ($this->updateJoinTable !== '') {
            $result = $this->buildUpdateJoin();
            $this->jsonSets = [];

            return $result;
        }

        $result = parent::update();
        $this->jsonSets = [];

        return $result;
    }

    private function buildUpdateJoin(): Statement
    {
        $this->bindings = [];
        $this->validateTable();

        $joinTable = $this->quote($this->updateJoinTable);
        if ($this->updateJoinAlias !== '') {
            $joinTable .= ' AS ' . $this->quote($this->updateJoinAlias);
        }

        $sql = 'UPDATE ' . $this->quote($this->table)
            . ' JOIN ' . $joinTable
            . ' ON ' . $this->resolveAndWrap($this->updateJoinLeft) . ' = ' . $this->resolveAndWrap($this->updateJoinRight);

        $assignments = $this->compileAssignments();

        if (empty($assignments)) {
            throw new ValidationException('No assignments for UPDATE. Call set() or setRaw() before update().');
        }

        $sql .= ' SET ' . \implode(', ', $assignments);

        $parts = [$sql];
        $this->compileWhereClauses($parts);

        return new Statement(\implode(' ', $parts), $this->getBindingValues(), executor: $this->executor);
    }

    public function deleteJoin(string $alias, string $table, string $left, string $right): static
    {
        $this->deleteAlias = $alias;
        $this->deleteJoinTable = $table;
        $this->deleteJoinLeft = $left;
        $this->deleteJoinRight = $right;

        return $this;
    }

    #[\Override]
    public function delete(): Statement
    {
        if ($this->deleteAlias !== '') {
            return $this->buildDeleteJoin();
        }

        return parent::delete();
    }

    private function buildDeleteJoin(): Statement
    {
        $this->bindings = [];
        $this->validateTable();

        $sql = 'DELETE ' . $this->quote($this->deleteAlias)
            . ' FROM ' . $this->quote($this->table) . ' AS ' . $this->quote($this->deleteAlias)
            . ' JOIN ' . $this->quote($this->deleteJoinTable)
            . ' ON ' . $this->resolveAndWrap($this->deleteJoinLeft) . ' = ' . $this->resolveAndWrap($this->deleteJoinRight);

        $parts = [$sql];
        $this->compileWhereClauses($parts);

        return new Statement(\implode(' ', $parts), $this->getBindingValues(), executor: $this->executor);
    }

    #[\Override]
    protected function groupConcatExpr(string $column, string $orderBy): string
    {
        $suffix = $orderBy === '' ? '' : ' ' . $orderBy;

        return 'GROUP_CONCAT(' . $column . $suffix . ' SEPARATOR ?)';
    }

    #[\Override]
    protected function jsonArrayAggExpr(string $column): string
    {
        return 'JSON_ARRAYAGG(' . $column . ')';
    }

    #[\Override]
    protected function jsonObjectAggExpr(string $keyColumn, string $valueColumn): string
    {
        return 'JSON_OBJECTAGG(' . $keyColumn . ', ' . $valueColumn . ')';
    }

    #[\Override]
    public function insertDefaultValues(): Statement
    {
        $this->bindings = [];
        $this->validateTable();

        return new Statement('INSERT INTO ' . $this->quote($this->table) . ' () VALUES ()', $this->getBindingValues(), executor: $this->executor);
    }

    #[\Override]
    public function withRollup(): static
    {
        $this->groupByModifier = 'WITH ROLLUP';

        return $this;
    }

    #[\Override]
    public function reset(): static
    {
        parent::reset();
        $this->hints = [];
        $this->jsonSets = [];
        $this->resetGroupByModifier();
        $this->updateJoinTable = '';
        $this->updateJoinLeft = '';
        $this->updateJoinRight = '';
        $this->updateJoinAlias = '';
        $this->deleteAlias = '';
        $this->deleteJoinTable = '';
        $this->deleteJoinLeft = '';
        $this->deleteJoinRight = '';

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
            return 'ST_Distance(ST_SRID(' . $attribute . ', 4326), ' . $this->geomFromText(4326) . ', \'metre\') ' . $operator . ' ?';
        }

        return 'ST_Distance(ST_SRID(' . $attribute . ', 0), ' . $this->geomFromText(0) . ') ' . $operator . ' ?';
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

        $expr = $function . '(' . $attribute . ', ' . $this->geomFromText(4326) . ')';

        return $not ? 'NOT ' . $expr : $expr;
    }

    /**
     * @param  array<mixed>  $values
     */
    #[\Override]
    protected function compileSpatialCoversPredicate(string $attribute, array $values, bool $not): string
    {
        return $this->compileSpatialPredicate('ST_Contains', $attribute, $values, $not);
    }

    protected function geomFromText(int $srid): string
    {
        return "ST_GeomFromText(?, {$srid}, 'axis-order=long-lat')";
    }

    /**
     * @param  array<mixed>  $values
     */
    #[\Override]
    protected function compileJsonContainsExpr(string $attribute, array $values, bool $not): string
    {
        $this->addBinding($this->encodeJsonPayload($values[0]));
        $expr = 'JSON_CONTAINS(' . $attribute . ', ?)';

        return $not ? 'NOT ' . $expr : $expr;
    }

    /**
     * @param  array<mixed>  $values
     */
    #[\Override]
    protected function compileJsonOverlapsExpr(string $attribute, array $values): string
    {
        /** @var array<mixed> $arr */
        $arr = $values[0];
        $this->addBinding($this->encodeJsonPayload($arr));

        return 'JSON_OVERLAPS(' . $attribute . ', ?)';
    }

    private function encodeJsonPayload(mixed $payload): string
    {
        try {
            return \json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ValidationException('Invalid JSON payload: ' . $exception->getMessage());
        }
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

        return 'JSON_EXTRACT(' . $attribute . ', \'$.' . $path . '\') ' . $operator . ' ?';
    }

}
