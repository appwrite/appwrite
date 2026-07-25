<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Health;

final class MessagingQueueTest extends HealthBase
{
    public function testMessagingQueue(): void
    {
        $response = $this->callGet('/health/queue/messaging');
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertIsInt($response['body']['size']);
        $this->assertLessThan(100, $response['body']['size']);

        $failure = $this->callGet('/health/queue/messaging', ['threshold' => '0']);
        $this->assertEquals(503, $failure['headers']['status-code']);
    }
}
