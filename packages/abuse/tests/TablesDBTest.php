<?php

declare(strict_types=1);

namespace Utopia\Abuse\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Abuse\Adapter\TimeLimit\Appwrite\TablesDB;
use Utopia\Abuse\Result;
use Utopia\Abuse\Tests\TimeLimit\RacingTablesDBClient;

final class TablesDBTest extends TestCase
{
    public function testConcurrentHitCannotTakeTheSameLastSlot(): void
    {
        $client = new RacingTablesDBClient(count: 1);
        $adapter = new TablesDB('login', 2, 60, $client, 'abuse');

        $competing = null;
        $client->race = function () use (&$competing, $adapter): void {
            $competing = $adapter->check();
        };

        $result = $adapter->check();

        $this->assertInstanceOf(Result::class, $competing);
        $this->assertFalse($competing->limited, 'the competing hit takes the last slot');
        $this->assertTrue($result->limited, 'the hit that lost the race is limited');
        $this->assertSame(2, $client->count);
    }

    public function testHitBelowTheLimitReportsTheCountBeforeIt(): void
    {
        $client = new RacingTablesDBClient(count: 1);

        $result = new TablesDB('login', 3, 60, $client, 'abuse')->check();

        $this->assertFalse($result->limited);
        $this->assertSame(1, $result->remaining);
        $this->assertSame(2, $client->count);
    }
}
