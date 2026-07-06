<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Health;

final class CertificatesQueueTest extends HealthBase
{
    public function testCertificatesQueue(): void
    {
        $response = $this->callGet('/health/queue/certificates');
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertIsInt($response['body']['size']);
        $this->assertLessThan(100, $response['body']['size']);

        $failure = $this->callGet('/health/queue/certificates', ['threshold' => '0']);
        $this->assertEquals(503, $failure['headers']['status-code']);
    }
}
