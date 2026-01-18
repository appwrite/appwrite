<?php

namespace Tests\E2E\Services\Health;

class TimeTest extends HealthBase
{
    public function testTimeSuccess(): void
    {
        $response = $this->callGet('/health/time');
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertIsInt($response['body']['remoteTime']);
        $this->assertIsInt($response['body']['localTime']);
        $this->assertNotEmpty($response['body']['remoteTime']);
        $this->assertNotEmpty($response['body']['localTime']);
        $this->assertLessThan(10, $response['body']['diff']);
    }
}
