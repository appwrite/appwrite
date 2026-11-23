<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Health;

final class HTTPTest extends HealthBase
{
    public function testHTTPSuccess(): void
    {
        $response = $this->callGet('/health');
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('pass', $response['body']['status']);
        $this->assertIsInt($response['body']['ping']);
        $this->assertGreaterThanOrEqual(0, $response['body']['ping']);
    }
}
