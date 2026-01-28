<?php

namespace Tests\E2E\Services\Health;

class FunctionsQueueTest extends HealthBase
{
    public function testFunctionsQueue(): void
    {
        $response = $this->callGet('/health/queue/functions');
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertIsInt($response['body']['size']);
        $this->assertLessThan(100, $response['body']['size']);

        $failure = $this->callGet('/health/queue/functions', ['threshold' => '0']);
        $this->assertEquals(503, $failure['headers']['status-code']);
    }
}
