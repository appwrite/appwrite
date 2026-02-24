<?php

namespace Tests\E2E\Services\Health;

class DatabasesQueueTest extends HealthBase
{
    public function testDatabasesQueue(): void
    {
        $response = $this->callGet('/health/queue/databases', ['name' => 'database_db_main-0']);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertIsInt($response['body']['size']);
        $this->assertLessThan(100, $response['body']['size']);

        $failure = $this->callGet('/health/queue/databases', ['name' => 'database_db_main-0', 'threshold' => '0']);
        $this->assertEquals(503, $failure['headers']['status-code']);
    }
}
