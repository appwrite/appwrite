<?php

namespace Tests\E2E\Services\Health;

class AuditsQueueTest extends HealthBase
{
    public function testAuditsQueue(): void
    {
        $response = $this->callGet('/health/queue/audits');
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertIsInt($response['body']['size']);
        $this->assertLessThan(100, $response['body']['size']);

        $failure = $this->callGet('/health/queue/audits', ['threshold' => '0']);
        $this->assertEquals(503, $failure['headers']['status-code']);
    }
}
