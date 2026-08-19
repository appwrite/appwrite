<?php

declare(strict_types=1);

namespace Tests\Unit\Usage\Fakes;

use Utopia\Query\Query;
use Utopia\Usage\Adapter as UsageAdapter;
use Utopia\Usage\Metric;

final class Adapter extends UsageAdapter
{
    /** @var array<int, array{metrics: array, type: string}> */
    public array $batches = [];

    public function getName(): string
    {
        return 'fake';
    }

    public function healthCheck(): array
    {
        return ['healthy' => true];
    }

    public function setup(): void
    {
    }

    public function addBatch(array $metrics, string $type, int $batchSize = 1000): bool
    {
        $this->batches[] = ['metrics' => $metrics, 'type' => $type];
        return true;
    }

    public function getTimeSeries(string $tenant, array $metrics, string $interval, string $startDate, string $endDate, array $queries = [], bool $zeroFill = true, ?string $type = null): array
    {
        return [];
    }

    public function getTotal(string $tenant, string $metric, array $queries = [], ?string $type = null): int
    {
        return 0;
    }

    public function getTotalBatch(string $tenant, array $metrics, array $queries = [], ?string $type = null): array
    {
        return [];
    }

    public function purge(string $tenant, array $queries = [], ?string $type = null): bool
    {
        return true;
    }

    /** @return array<Metric> */
    public function find(string $tenant, array $queries = [], ?string $type = null): array
    {
        return [];
    }

    public function count(string $tenant, array $queries = [], ?string $type = null, ?int $max = null): int
    {
        return 0;
    }

    public function sum(string $tenant, array $queries = [], string $attribute = 'value', string $type = 'event'): int
    {
        return 0;
    }

    /** @return array<Metric> */
    public function findDaily(string $tenant, array $queries = []): array
    {
        return [];
    }

    public function sumDaily(string $tenant, array $queries = [], string $attribute = 'value'): int
    {
        return 0;
    }

    public function sumDailyBatch(string $tenant, array $metrics, array $queries = []): array
    {
        return [];
    }
}
