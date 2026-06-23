<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Health;

final class DeletesQueueTest extends HealthBase
{
    public function testDeletesQueue(): void
    {
        $response = $this->callGet('/health/queue/deletes');
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertIsInt($response['body']['size']);
        $this->assertLessThan(100, $response['body']['size']);

        $failure = $this->callGet('/health/queue/deletes', ['threshold' => '0']);
        $this->assertEquals(503, $failure['headers']['status-code']);
    }
}
