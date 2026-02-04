<?php

namespace Tests\E2E\Services\Health;

class StorageLocalTest extends HealthBase
{
    public function testStorageLocal(): void
    {
        $response = $this->callGet('/health/storage/local');
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('pass', $response['body']['status']);
        $this->assertIsInt($response['body']['ping']);
        $this->assertLessThan(100, $response['body']['ping']);
    }
}
