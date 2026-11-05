<?php

declare(strict_types=1);

namespace Tests\Unit\Usage;

use Appwrite\Usage\Connection;
use PHPUnit\Framework\TestCase;
use Utopia\Usage\Adapter\ClickHouse;
use Utopia\Usage\Usage;

final class ConnectionTest extends TestCase
{
    public function testSetupAppliesTheSchemaThroughAWrappedConnection(): void
    {
        $usage = new class (new ClickHouse('clickhouse')) extends Usage {
            public int $setups = 0;

            public function setup(): void
            {
                $this->setups++;
            }
        };

        Connection::fromUsage($usage)->setup();

        $this->assertSame(1, $usage->setups, 'The usage-setup task must apply the schema on a wrapped connection');
    }

    public function testWrappedConnectionIsReadyWithoutAHealthCheck(): void
    {
        $usage = new class (new ClickHouse('clickhouse')) extends Usage {
            public function healthCheck(): array
            {
                throw new \LogicException('A wrapped connection must not run a health check');
            }
        };

        $this->assertTrue(Connection::fromUsage($usage)->isReady());
    }

    public function testHealthCheckProbesEveryTableWithoutReadingAcrossTenants(): void
    {
        $usage = new class (new ClickHouse('clickhouse')) extends Usage {
            /** @var list<string> */
            public array $probes = [];

            public function healthCheck(): array
            {
                return ['healthy' => true];
            }

            public function find(string $tenant, array $queries = [], ?string $type = null): array
            {
                $this->probes[] = (string) $type;

                return [];
            }

            public function findAcrossTenants(array $queries = [], ?string $type = null): array
            {
                throw new \LogicException('A cross-tenant probe scans purged rows');
            }

            public function findDaily(string $tenant, array $queries = []): array
            {
                $this->probes[] = 'daily';

                return [];
            }
        };

        $health = Connection::fromUsage($usage)->healthCheck();

        $this->assertTrue($health['schemaReady']);
        $this->assertEqualsCanonicalizing([Usage::TYPE_EVENT, Usage::TYPE_GAUGE, 'daily'], $usage->probes);
    }

    public function testHealthCheckReportsTheSchemaAsNotReadyWhenAProbeFails(): void
    {
        $usage = new class (new ClickHouse('clickhouse')) extends Usage {
            public function healthCheck(): array
            {
                return ['healthy' => true];
            }

            public function find(string $tenant, array $queries = [], ?string $type = null): array
            {
                throw new \RuntimeException('Unknown identifier: service');
            }
        };

        $health = Connection::fromUsage($usage)->healthCheck();

        $this->assertFalse($health['schemaReady']);
        $this->assertSame('Unknown identifier: service', $health['error']);
    }
}
