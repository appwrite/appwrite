<?php

namespace Utopia\Usage;

/**
 * Tenant-scoped view over a Usage instance.
 *
 * Binds a tenant once at construction and forwards every query/mutation to the
 * underlying Usage with that tenant pre-filled — so callers that only ever
 * touch one tenant don't repeat it on every call.
 */
class Tenant
{
    private Usage $usage;

    private string $tenant;

    /**
     * @param  Usage  $usage  The underlying (tenant-agnostic) Usage instance
     * @param  string  $tenant  Tenant this view is scoped to (non-empty)
     */
    public function __construct(Usage $usage, string $tenant)
    {
        // Reject '' at construction: an empty scope would silently read/write
        // the empty tenant in shared-tables mode. ("0" is a valid id.)
        if ($tenant === '') {
            throw new \InvalidArgumentException('Tenant cannot be empty');
        }

        $this->usage = $usage;
        $this->tenant = $tenant;
    }

    /**
     * Add metrics in batch, stamping this view's tenant onto each entry.
     *
     * @param array<array{metric: string, value: int, tags?: array<string,mixed>}> $metrics
     * @param string $type Metric type: 'event' or 'gauge'
     * @param int $batchSize Maximum number of metrics per INSERT statement
     * @return bool
     * @throws \Exception
     */
    public function addBatch(array $metrics, string $type, int $batchSize = 1000): bool
    {
        foreach ($metrics as &$metric) {
            $metric['tenant'] = $this->tenant;
        }
        unset($metric);

        return $this->usage->addBatch($metrics, $type, $batchSize);
    }

    /**
     * @param array<string> $metrics
     * @param array<\Utopia\Query\Query> $queries
     * @return array<string, array{total: float, data: array<array{value: float, date: string}>}>
     * @throws \Exception
     */
    public function getTimeSeries(array $metrics, string $interval, string $startDate, string $endDate, array $queries = [], bool $zeroFill = true, ?string $type = null): array
    {
        return $this->usage->getTimeSeries($this->tenant, $metrics, $interval, $startDate, $endDate, $queries, $zeroFill, $type);
    }

    /**
     * @param array<\Utopia\Query\Query> $queries
     * @throws \Exception
     */
    public function getTotal(string $metric, array $queries = [], ?string $type = null): int
    {
        return $this->usage->getTotal($this->tenant, $metric, $queries, $type);
    }

    /**
     * @param array<string> $metrics
     * @param array<\Utopia\Query\Query> $queries
     * @return array<string, int>
     * @throws \Exception
     */
    public function getTotalBatch(array $metrics, array $queries = [], ?string $type = null): array
    {
        return $this->usage->getTotalBatch($this->tenant, $metrics, $queries, $type);
    }

    /**
     * @param array<\Utopia\Query\Query> $queries
     * @throws \Exception
     */
    public function purge(array $queries = [], ?string $type = null): bool
    {
        return $this->usage->purge($this->tenant, $queries, $type);
    }

    /**
     * @param array<\Utopia\Query\Query> $queries
     * @return array<Metric>
     * @throws \Exception
     */
    public function find(array $queries = [], ?string $type = null): array
    {
        return $this->usage->find($this->tenant, $queries, $type);
    }

    /**
     * @param array<\Utopia\Query\Query> $queries
     * @throws \Exception
     */
    public function count(array $queries = [], ?string $type = null, ?int $max = null): int
    {
        return $this->usage->count($this->tenant, $queries, $type, $max);
    }

    /**
     * @param array<\Utopia\Query\Query> $queries
     * @throws \Exception
     */
    public function sum(array $queries = [], string $attribute = 'value', string $type = Usage::TYPE_EVENT): int
    {
        return $this->usage->sum($this->tenant, $queries, $attribute, $type);
    }

    /**
     * @param array<\Utopia\Query\Query> $queries
     * @return array<Metric>
     * @throws \Exception
     */
    public function findDaily(array $queries = []): array
    {
        return $this->usage->findDaily($this->tenant, $queries);
    }

    /**
     * @param array<\Utopia\Query\Query> $queries
     * @throws \Exception
     */
    public function sumDaily(array $queries = [], string $attribute = 'value'): int
    {
        return $this->usage->sumDaily($this->tenant, $queries, $attribute);
    }

    /**
     * @param array<string> $metrics
     * @param array<\Utopia\Query\Query> $queries
     * @return array<string, int>
     * @throws \Exception
     */
    public function sumDailyBatch(array $metrics, array $queries = []): array
    {
        return $this->usage->sumDailyBatch($this->tenant, $metrics, $queries);
    }
}
