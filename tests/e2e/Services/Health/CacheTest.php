<?php

namespace Tests\E2E\Services\Health;

class CacheTest extends HealthBase
{
    public function testCacheSuccess(): void
    {
        $response = $this->callGet('/health/cache');
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertIsArray($response['body']['statuses']);
        $this->assertIsInt($response['body']['statuses'][0]['ping']);
        $this->assertLessThan(100, $response['body']['statuses'][0]['ping']);
        $this->assertEquals('pass', $response['body']['statuses'][0]['status']);
    }
}
