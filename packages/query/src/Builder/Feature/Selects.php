<?php

namespace Utopia\Query\Builder\Feature;

use Closure;
use Utopia\Query\Builder\Statement;
use Utopia\Query\NullsPosition;

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

    public function sortAsc(string $attribute, ?NullsPosition $nulls = null): static;

    public function sortDesc(string $attribute, ?NullsPosition $nulls = null): static;

    public function sortRandom(): static;

    public function limit(int $value): static;

    public function offset(int $value): static;

    public function fetch(int $count, bool $withTies = false): static;

    public function page(int $page, int $perPage = 25): static;

    public function cursorAfter(mixed $value): static;

    public function cursorBefore(mixed $value): static;

    public function when(bool $condition, Closure $callback): static;

    public function build(): Statement;

    public function toRawSql(): string;

    /**
     * @return list<mixed>
     */
    public function getBindings(): array;

    public function reset(): static;
}
