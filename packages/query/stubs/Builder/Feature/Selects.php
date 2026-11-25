<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

namespace Utopia\Query\Builder\Feature;

interface Selects
{
    public function from(string $table = '', string $alias = ''): static;
    /**
     * @param  string|array<string>  $columns
     * @param  list<mixed>  $bindings
     */
    public function select(string|array $columns, array $bindings = []): static;
    public function distinct(): static;
    /**
     * @param  array<\Utopia\Query\Query>  $queries
     */
    public function filter(array $queries): static;
    /**
     * @param  array<\Utopia\Query\Query>  $queries
     */
    public function queries(array $queries): static;
    public function sortAsc(string $attribute, ?\Utopia\Query\NullsPosition $nulls = null): static;
    public function sortDesc(string $attribute, ?\Utopia\Query\NullsPosition $nulls = null): static;
    public function sortRandom(): static;
    public function limit(int $value): static;
    public function offset(int $value): static;
    public function fetch(int $count, bool $withTies = false): static;
    public function page(int $page, int $perPage = 25): static;
    /**
     * Page after a row, read from its cursor column.
     *
     * @param  array<mixed>|object  $value
     */
    public function cursorAfter(array|object $value): static;
    /**
     * Page before a row, read from its cursor column.
     *
     * @param  array<mixed>|object  $value
     */
    public function cursorBefore(array|object $value): static;
    public function reset(): static;
    /**
     * @return list<mixed>
     */
    public function getBindings(): array;
    /**
     * The built query with its bindings inlined, for logs and debugging only.
     * Never execute it: values are escaped without knowing the connection's
     * charset or SQL mode, which only bound parameters are safe against.
     */
    public function toRawSql(): string;
    public function when(bool $condition, \Closure $callback): static;
    public function build(): \Utopia\Query\Builder\Statement;
}
