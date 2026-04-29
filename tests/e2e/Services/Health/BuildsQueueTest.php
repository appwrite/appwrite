<?php

namespace Tests\E2E\Services\Health;

class BuildsQueueTest extends HealthBase
{
    public function testBuildsQueue(): void
    {
        $response = $this->callGet('/health/queue/builds');
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertIsInt($response['body']['size']);
        $this->assertLessThan(100, $response['body']['size']);

        $failure = $this->callGet('/health/queue/builds', ['threshold' => '0']);
        $this->assertEquals(503, $failure['headers']['status-code']);
    }
}
