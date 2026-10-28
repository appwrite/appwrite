<?php

namespace Tests\Unit\Alert;

use Utopia\Usage\Tenant;

/**
 * A usage tenant that answers each metric from a fixed table and records the
 * series it was asked for, so a test sees which usage names a meter reads.
 */
final class RecordingTenant extends Tenant
{
    /**
     * @var list<array{metric: string, type: ?string}>
     */
    public array $reads = [];

    /**
     * @param array<string, int> $totals
     */
    public function __construct(private readonly array $totals = [])
    {
    }

    #[\Override]
    public function getTotal(string $metric, array $queries = [], ?string $type = null): int
    {
        $this->reads[] = ['metric' => $metric, 'type' => $type];

        return $this->totals[$metric] ?? 0;
    }
}
