<?php

namespace Utopia\Tests\Usage;

use PHPUnit\Framework\TestCase;
use Utopia\Usage\Adapter;
use Utopia\Usage\Tenant;
use Utopia\Usage\Usage;

/**
 * Records the tenant passed to each method so the Tenant decorator can be
 * tested without a backend.
 */
class TenantRecordingAdapter extends Adapter
{
    public ?string $lastTenant = null;

    /** @var array<int, array<string, mixed>> */
    public array $lastMetrics = [];

    public function getName(): string
    {
        return 'tenant-recording';
    }

    /**
     * @return array<string, mixed>
     */
    public function healthCheck(): array
    {
        return ['healthy' => true];
    }

    public function setup(): void
    {
    }

    /**
     * @param array<int, array<string, mixed>> $metrics
     */
    public function addBatch(array $metrics, string $type, int $batchSize = 1000): bool
    {
        $this->lastMetrics = $metrics;
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function getTimeSeries(string $tenant, array $metrics, string $interval, string $startDate, string $endDate, array $queries = [], bool $zeroFill = true, ?string $type = null): array
    {
        $this->lastTenant = $tenant;
        return [];
    }

    public function getTotal(string $tenant, string $metric, array $queries = [], ?string $type = null): int
    {
        $this->lastTenant = $tenant;
        return 0;
    }

    /**
     * @return array<string, int>
     */
    public function getTotalBatch(string $tenant, array $metrics, array $queries = [], ?string $type = null): array
    {
        $this->lastTenant = $tenant;
        return [];
    }

    public function purge(string $tenant, array $queries = [], ?string $type = null): bool
    {
        $this->lastTenant = $tenant;
        return true;
    }

    /**
     * @return array<mixed>
     */
    public function find(string $tenant, array $queries = [], ?string $type = null): array
    {
        $this->lastTenant = $tenant;
        return [];
    }

    public function count(string $tenant, array $queries = [], ?string $type = null, ?int $max = null): int
    {
        $this->lastTenant = $tenant;
        return 0;
    }

    public function sum(string $tenant, array $queries = [], string $attribute = 'value', string $type = Usage::TYPE_EVENT): int
    {
        $this->lastTenant = $tenant;
        return 0;
    }

    /**
     * @return array<mixed>
     */
    public function findDaily(string $tenant, array $queries = []): array
    {
        $this->lastTenant = $tenant;
        return [];
    }

    public function sumDaily(string $tenant, array $queries = [], string $attribute = 'value'): int
    {
        $this->lastTenant = $tenant;
        return 0;
    }

    /**
     * @return array<string, int>
     */
    public function sumDailyBatch(string $tenant, array $metrics, array $queries = []): array
    {
        $this->lastTenant = $tenant;
        return [];
    }
}

class TenantTest extends TestCase
{
    private TenantRecordingAdapter $adapter;

    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->adapter = new TenantRecordingAdapter();
        $this->tenant = new Tenant(new Usage($this->adapter), 'p1');
    }

    public function testEmptyTenantThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Tenant cannot be empty');
        new Tenant(new Usage($this->adapter), '');
    }

    public function testAddBatchStampsBoundTenantOntoEveryMetric(): void
    {
        $this->tenant->addBatch([
            ['metric' => 'requests', 'value' => 10, 'tags' => []],
            ['metric' => 'bandwidth', 'value' => 20, 'tags' => []],
        ], Usage::TYPE_EVENT);

        $this->assertEquals('p1', $this->adapter->lastMetrics[0]['tenant']);
        $this->assertEquals('p1', $this->adapter->lastMetrics[1]['tenant']);
    }

    public function testFindForwardsBoundTenant(): void
    {
        $this->tenant->find([], Usage::TYPE_EVENT);
        $this->assertEquals('p1', $this->adapter->lastTenant);
    }

    public function testCountForwardsBoundTenant(): void
    {
        $this->tenant->count([], Usage::TYPE_EVENT);
        $this->assertEquals('p1', $this->adapter->lastTenant);
    }

    public function testSumForwardsBoundTenant(): void
    {
        $this->tenant->sum([], 'value', Usage::TYPE_EVENT);
        $this->assertEquals('p1', $this->adapter->lastTenant);
    }

    public function testGetTotalForwardsBoundTenant(): void
    {
        $this->tenant->getTotal('requests', [], Usage::TYPE_EVENT);
        $this->assertEquals('p1', $this->adapter->lastTenant);
    }

    public function testGetTotalBatchForwardsBoundTenant(): void
    {
        $this->tenant->getTotalBatch(['requests'], [], Usage::TYPE_EVENT);
        $this->assertEquals('p1', $this->adapter->lastTenant);
    }

    public function testGetTimeSeriesForwardsBoundTenant(): void
    {
        $this->tenant->getTimeSeries(['requests'], '1h', '2026-01-01 00:00:00', '2026-01-02 00:00:00');
        $this->assertEquals('p1', $this->adapter->lastTenant);
    }

    public function testPurgeForwardsBoundTenant(): void
    {
        $this->tenant->purge([], Usage::TYPE_EVENT);
        $this->assertEquals('p1', $this->adapter->lastTenant);
    }

    public function testFindDailyForwardsBoundTenant(): void
    {
        $this->tenant->findDaily([]);
        $this->assertEquals('p1', $this->adapter->lastTenant);
    }

    public function testSumDailyForwardsBoundTenant(): void
    {
        $this->tenant->sumDaily([]);
        $this->assertEquals('p1', $this->adapter->lastTenant);
    }

    public function testSumDailyBatchForwardsBoundTenant(): void
    {
        $this->tenant->sumDailyBatch(['requests'], []);
        $this->assertEquals('p1', $this->adapter->lastTenant);
    }
}
