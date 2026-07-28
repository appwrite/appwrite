<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Health;

final class AntiVirusTest extends HealthBase
{
    public function testAntiVirus(): void
    {
        $response = $this->callGet('/health/anti-virus');
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['status']);
        $this->assertIsString($response['body']['status']);
        $this->assertIsString($response['body']['version']);
    }
}
