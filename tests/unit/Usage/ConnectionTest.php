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
}
