<?php

namespace Tests\E2E\Services\Health;

class MigrationsQueueTest extends HealthBase
{
    public function testMigrationsQueue(): void
    {
        $response = $this->callGet('/health/queue/migrations');
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertIsInt($response['body']['size']);
        $this->assertLessThan(100, $response['body']['size']);

        $failure = $this->callGet('/health/queue/migrations', ['threshold' => '0']);
        $this->assertEquals(503, $failure['headers']['status-code']);
    }
}
