<?php

namespace Tests\E2E\Services\Health;

class HTTPTest extends HealthBase
{
    public function testHTTPSuccess(): void
    {
        $response = $this->callGet('/health');
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('pass', $response['body']['status']);
        $this->assertIsInt($response['body']['ping']);
        $this->assertLessThan(100, $response['body']['ping']);
    }
}
