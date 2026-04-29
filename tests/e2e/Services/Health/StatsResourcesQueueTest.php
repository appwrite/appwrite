<?php

namespace Tests\E2E\Services\Health;

class StatsResourcesQueueTest extends HealthBase
{
    public function testStatsResources(): void
    {
        $response = $this->callGet('/health/queue/stats-resources');
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertIsInt($response['body']['size']);
        $this->assertLessThan(100, $response['body']['size']);

        $failure = $this->callGet('/health/queue/stats-resources', ['threshold' => '0']);
        $this->assertEquals(503, $failure['headers']['status-code']);
    }
}
