<?php

namespace Tests\Unit\Alert;

use Utopia\Query\Query;
use Utopia\Usage\Tenant;

/**
 * A usage tenant that answers each metric from a fixed table and records the
 * series it was asked for, so a test sees which usage names and filters a
 * meter reads. A metric missing from the table has no rows at all.
 */
final class RecordingTenant extends Tenant
{
    /**
     * @var list<array{metric: string, type: ?string, queries: list<string>}>
     */
    public array $reads = [];

    /**
     * @param array<string, int|\Closure(list<Query>): int> $totals
     */
    public function __construct(private readonly array $totals = [])
    {
    }

    #[\Override]
    public function getTotal(string $metric, array $queries = [], ?string $type = null): int
    {
        $this->reads[] = [
            'metric' => $metric,
            'type' => $type,
            'queries' => \array_map(static fn (Query $query): string => $query->getMethod()->value . ':' . $query->getAttribute() . '=' . \implode(',', $query->getValues()), $queries),
        ];

        $total = $this->totals[$metric] ?? 0;

        return $total instanceof \Closure ? $total($queries) : $total;
    }

    /**
     * A series has rows when the table lists it, whatever its total.
     */
    #[\Override]
    public function count(array $queries = [], ?string $type = null, ?int $max = null): int
    {
        foreach ($queries as $query) {
            if ($query->getAttribute() === 'metric') {
                return \array_key_exists((string) $query->getValues()[0], $this->totals) ? 1 : 0;
            }
        }

        return 0;
    }
}
