<?php

declare(strict_types=1);

namespace Tests\Unit\Usage;

use Utopia\Usage\Adapter\ClickHouse;
use Utopia\Usage\Metric;
use Utopia\Usage\Usage;

/**
 * A usage store that never reaches ClickHouse. It reports itself healthy and
 * records the tenant and type of every read it is asked for, so a test sees
 * what a schema probe was scoped to. A read fails with $failure when one is
 * given, as a missing table or column would.
 */
final class RecordingUsage extends Usage
{
    /**
     * @var list<array{tenant: ?string, type: ?string}>
     */
    public array $reads = [];

    public function __construct(private readonly ?\Throwable $failure = null)
    {
        parent::__construct(new ClickHouse('clickhouse'));
    }

    #[\Override]
    public function healthCheck(): array
    {
        return ['healthy' => true];
    }

    #[\Override]
    public function find(string $tenant, array $queries = [], ?string $type = null): array
    {
        return $this->read($tenant, $type);
    }

    #[\Override]
    public function findAcrossTenants(array $queries = [], ?string $type = null): array
    {
        return $this->read(null, $type);
    }

    #[\Override]
    public function findDaily(string $tenant, array $queries = []): array
    {
        return $this->read($tenant, 'daily');
    }

    /**
     * @return array<Metric>
     */
    private function read(?string $tenant, ?string $type): array
    {
        $this->reads[] = ['tenant' => $tenant, 'type' => $type];

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return [];
    }
}
