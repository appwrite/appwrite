<?php

namespace Utopia\Query\Builder\Feature;

interface Json
{
    // Filter operations (added to WHERE clause)

    public function filterJsonContains(string $attribute, mixed $value): static;

    public function filterJsonNotContains(string $attribute, mixed $value): static;

    /**
     * @param  array<mixed>  $values
     */
    public function filterJsonOverlaps(string $attribute, array $values): static;

    public function filterJsonPath(string $attribute, string $path, string $operator, mixed $value): static;

    // Mutation operations (for UPDATE SET)

    /**
     * @param  array<mixed>  $values
     */
    public function setJsonAppend(string $column, array $values): static;

    /**
     * @param  array<mixed>  $values
     */
    public function setJsonPrepend(string $column, array $values): static;

    public function setJsonInsert(string $column, int $index, mixed $value): static;

    public function setJsonRemove(string $column, mixed $value): static;

    /**
     * @param  array<mixed>  $values
     */
    public function setJsonIntersect(string $column, array $values): static;

    /**
     * @param  array<mixed>  $values
     */
    public function setJsonDiff(string $column, array $values): static;

    public function setJsonUnique(string $column): static;

    /**
     * Set a JSON path to a value (or NULL to clear).
     *
     * Dialect mapping:
     *   MySQL:      JSON_SET(<col>, <path>, <value>)
     *   PostgreSQL: jsonb_set(<col>, <path-array>, to_jsonb(<value>), true)
     *
     * The <path> must start with '$' in MySQL / JSONPath style. The implementation
     * translates to PG's text[] form for jsonb_set.
     */
    public function setJsonPath(string $column, string $path, mixed $value): static;
}
