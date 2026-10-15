<?php

declare(strict_types=1);

namespace Tests\Unit\Mqtt\Fakes;

use Utopia\Database\Database;
use Utopia\Database\Query;

/**
 * A Database that runs a callback before one chosen find on a collection, as another coroutine
 * would while that query yields, and records the queries of every find.
 */
final class InterleavingDatabase extends Database
{
    private string $collection = '';

    private int $call = 0;

    private ?\Closure $callback = null;

    /** @var array<string, list<array<Query>>> collection => queries of each find so far */
    private array $finds = [];

    public function interleave(string $collection, int $call, \Closure $callback): void
    {
        $this->collection = $collection;
        $this->call = $call;
        $this->callback = $callback;
    }

    /** @return list<array<Query>> */
    public function finds(string $collection): array
    {
        return $this->finds[$collection] ?? [];
    }

    public function find(string $collection, array $queries = [], string $forPermission = Database::PERMISSION_READ): array
    {
        $this->finds[$collection][] = \array_map(fn (Query $query): Query => clone $query, $queries);

        if ($this->callback !== null && $collection === $this->collection && \count($this->finds[$collection]) === $this->call) {
            ($this->callback)();
        }

        return parent::find($collection, $queries, $forPermission);
    }
}
