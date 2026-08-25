<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Health;

final class ExecutionsTest extends HealthBase
{
    public function testExecutionsSuccess(): void
    {
        $response = $this->callGet('/health/executions');
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertSame('Executions.ClickHouse', $response['body']['statuses'][0]['name']);
        $this->assertSame('pass', $response['body']['statuses'][0]['status']);
    }
}
