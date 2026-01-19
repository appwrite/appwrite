<?php

namespace Tests\E2E\Services\Health;

class StatsUsageQueueTest extends HealthBase
{
    public function testStatsUsage(): void
    {
        $response = $this->callGet('/health/queue/stats-usage');
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertIsInt($response['body']['size']);
        $this->assertLessThan(100, $response['body']['size']);

        $failure = $this->callGet('/health/queue/stats-usage', ['threshold' => '0']);
        $this->assertEquals(503, $failure['headers']['status-code']);
    }
}
