<?php

namespace Utopia\Query\Builder\Feature;

interface StringAggregates
{
    /**
     * Concatenate column values into a string.
     * Compiles to GROUP_CONCAT (MySQL) or STRING_AGG (PostgreSQL).
     *
     * @param  list<string>|null  $orderBy  Columns for ORDER BY (prefix with - for DESC)
     */
    public function groupConcat(string $column, string $separator = ',', string $alias = '', ?array $orderBy = null): static;

    /**
     * Aggregate values into a JSON array.
     * Compiles to JSON_ARRAYAGG (MySQL) or JSON_AGG (PostgreSQL).
     */
    public function jsonArrayAgg(string $column, string $alias = ''): static;

    /**
     * Aggregate key-value pairs into a JSON object.
     * Compiles to JSON_OBJECTAGG (MySQL) or JSON_OBJECT_AGG (PostgreSQL).
     */
    public function jsonObjectAgg(string $keyColumn, string $valueColumn, string $alias = ''): static;
}
