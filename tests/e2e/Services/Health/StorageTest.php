<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Health;

final class StorageTest extends HealthBase
{
    public function testStorage(): void
    {
        $response = $this->callGet('/health/storage');
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('pass', $response['body']['status']);
        $this->assertIsInt($response['body']['ping']);
        $this->assertLessThan(100, $response['body']['ping']);
    }
}
