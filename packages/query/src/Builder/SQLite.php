<?php

namespace Utopia\Query\Builder;

use Utopia\Query\AST\Serializer;
use Utopia\Query\AST\Serializer\SQLite as SQLiteSerializer;
use Utopia\Query\Builder\Feature\ConditionalAggregates;
use Utopia\Query\Builder\Feature\InsertOrIgnore;
use Utopia\Query\Builder\Feature\Json;
use Utopia\Query\Builder\Feature\StringAggregates;
use Utopia\Query\Builder\Feature\Upsert;
use Utopia\Query\Builder\Feature\UpsertSelect;
use Utopia\Query\Exception\UnsupportedException;
use Utopia\Query\Exception\ValidationException;
use Utopia\Query\Method;

class SQLite extends SQL implements Json, ConditionalAggregates, StringAggregates, InsertOrIgnore, Upsert, UpsertSelect
{
    use Trait\ConditionalAggregates;
    use Trait\StringAggregates;
    use Trait\Upsert;
    use Trait\UpsertSelect;

    /** @var array<string, Condition> */
    protected array $jsonSets = [];

    #[\Override]
    protected function createAstSerializer(): Serializer
    {
        return new SQLiteSerializer();
    }

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
        throw new UnsupportedException('REGEXP is not natively supported in SQLite.');
    }

    /**
     * @param  array<mixed>  $values
     */
    #[\Override]
    protected function compileSearchExpr(string $attribute, array $values, bool $not): string
    {
        throw new UnsupportedException('Full-text search is not supported in the SQLite query builder.');
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
        return 'excluded.' . $wrapped;
    }

    #[\Override]
    public function insertOrIgnore(): Statement
    {
        $this->bindings = [];
        [$sql, $bindings] = $this->compileInsertBody();
        foreach ($bindings as $binding) {
            $this->addBinding($binding);
        }

        $sql = \preg_replace('/^INSERT INTO/', 'INSERT OR IGNORE INTO', $sql, 1) ?? $sql;

        return new Statement($sql, $this->getBindingValues(), executor: $this->executor);
    }

    #[\Override]
    public function setJsonAppend(string $column, array $values): static
    {
        $this->jsonSets[$column] = new Condition(
            'json_group_array(value) FROM (SELECT value FROM json_each(IFNULL(' . $this->resolveAndWrap($column) . ', \'[]\')) UNION ALL SELECT value FROM json_each(?))',
            [\json_encode($values)],
        );

        return $this;
    }

    #[\Override]
    public function setJsonPrepend(string $column, array $values): static
    {
        $this->jsonSets[$column] = new Condition(
            'json_group_array(value) FROM (SELECT value FROM json_each(?) UNION ALL SELECT value FROM json_each(IFNULL(' . $this->resolveAndWrap($column) . ', \'[]\')))',
            [\json_encode($values)],
        );

        return $this;
    }

    #[\Override]
    public function setJsonInsert(string $column, int $index, mixed $value): static
    {
        $this->jsonSets[$column] = new Condition(
            'json_insert(' . $this->resolveAndWrap($column) . ', \'$[' . $index . ']\', json(?))',
            [\json_encode($value)],
        );

        return $this;
    }

    #[\Override]
    public function setJsonRemove(string $column, mixed $value): static
    {
        $wrapped = $this->resolveAndWrap($column);
        $this->jsonSets[$column] = new Condition(
            '(SELECT json_group_array(value) FROM json_each(' . $wrapped . ') WHERE value != json(?))',
            [\json_encode($value)],
        );

        return $this;
    }

    #[\Override]
    public function setJsonIntersect(string $column, array $values): static
    {
        $wrapped = $this->resolveAndWrap($column);
        $this->setRaw($column, '(SELECT json_group_array(value) FROM json_each(IFNULL(' . $wrapped . ', \'[]\')) WHERE value IN (SELECT value FROM json_each(?)))', [\json_encode($values)]);

        return $this;
    }

    #[\Override]
    public function setJsonDiff(string $column, array $values): static
    {
        $wrapped = $this->resolveAndWrap($column);
        $this->setRaw($column, '(SELECT json_group_array(value) FROM json_each(IFNULL(' . $wrapped . ', \'[]\')) WHERE value NOT IN (SELECT value FROM json_each(?)))', [\json_encode($values)]);

        return $this;
    }

    #[\Override]
    public function setJsonUnique(string $column): static
    {
        $wrapped = $this->resolveAndWrap($column);
        $this->setRaw($column, '(SELECT json_group_array(DISTINCT value) FROM json_each(IFNULL(' . $wrapped . ', \'[]\')))');

        return $this;
    }

    #[\Override]
    public function setJsonPath(string $column, string $path, mixed $value): static
    {
        if (! \str_starts_with($path, '$')) {
            throw new ValidationException('JSON path must start with \'$\': ' . $path);
        }

        $this->jsonSets[$column] = new Condition(
            'json_set(' . $this->resolveAndWrap($column) . ', ?, ?)',
            [$path, $value],
        );

        return $this;
    }

    #[\Override]
    public function update(): Statement
    {
        foreach ($this->jsonSets as $col => $condition) {
            $this->setRaw($col, $condition->expression, $condition->bindings);
        }

        $result = parent::update();
        $this->jsonSets = [];

        return $result;
    }

    /**
     * @param  array<mixed>  $values
     */
    #[\Override]
    protected function compileSpatialDistance(Method $method, string $attribute, array $values): string
    {
        throw new UnsupportedException('Spatial distance queries are not supported in SQLite.');
    }

    /**
     * @param  array<mixed>  $values
     */
    #[\Override]
    protected function compileSpatialPredicate(string $function, string $attribute, array $values, bool $not): string
    {
        throw new UnsupportedException('Spatial predicates are not supported in SQLite.');
    }

    /**
     * @param  array<mixed>  $values
     */
    #[\Override]
    protected function compileSpatialCoversPredicate(string $attribute, array $values, bool $not): string
    {
        throw new UnsupportedException('Spatial covers predicates are not supported in SQLite.');
    }

    /**
     * @param  array<mixed>  $values
     */
    #[\Override]
    protected function compileJsonContainsExpr(string $attribute, array $values, bool $not): string
    {
        /** @var array<mixed> $arr */
        $arr = $values[0];
        $placeholders = [];
        foreach ((array) $arr as $item) {
            $this->addBinding(\json_encode($item));
            $placeholders[] = '?';
        }

        $conditions = \array_map(
            fn (string $p) => 'EXISTS (SELECT 1 FROM json_each(' . $attribute . ') WHERE json_each.value = json(' . $p . '))',
            $placeholders
        );

        $expr = '(' . \implode(' AND ', $conditions) . ')';

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
        $placeholders = [];
        foreach ((array) $arr as $item) {
            $this->addBinding(\json_encode($item));
            $placeholders[] = '?';
        }

        $conditions = \array_map(
            fn (string $p) => 'EXISTS (SELECT 1 FROM json_each(' . $attribute . ') WHERE json_each.value = json(' . $p . '))',
            $placeholders
        );

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

        return 'json_extract(' . $attribute . ', \'$.' . $path . '\') ' . $operator . ' ?';
    }

    #[\Override]
    protected function groupConcatExpr(string $column, string $orderBy): string
    {
        $suffix = $orderBy === '' ? '' : ' ' . $orderBy;

        return 'GROUP_CONCAT(' . $column . $suffix . ', ?)';
    }

    #[\Override]
    protected function jsonArrayAggExpr(string $column): string
    {
        return 'json_group_array(' . $column . ')';
    }

    #[\Override]
    protected function jsonObjectAggExpr(string $keyColumn, string $valueColumn): string
    {
        return 'json_group_object(' . $keyColumn . ', ' . $valueColumn . ')';
    }

    #[\Override]
    public function reset(): static
    {
        parent::reset();
        $this->jsonSets = [];

        return $this;
    }

    #[\Override]
    protected function wrapUnionMember(string $sql): string
    {
        // SQLite's compound-SELECT parser rejects parenthesised members,
        // so emit the bare SELECT and rely on the UNION keyword alone.
        return $sql;
    }
}
