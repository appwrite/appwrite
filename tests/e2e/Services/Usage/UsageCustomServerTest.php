<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Usage;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\System\System;

final class UsageCustomServerTest extends Scope
{
    use ProjectCustom;
    use SideServer;

    public function testListEventsReturnsRequestedEmptySeries(): void
    {
        $this->skipUnlessUsageStatsEnabled();

        $response = $this->call('/usage/events', [
            'metrics' => ['test.unknown.event'],
            'interval' => '1h',
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('1h', $response['body']['interval']);
        $this->assertSame('test.unknown.event', $response['body']['metrics'][0]['metric']);
        $this->assertSame([], $response['body']['metrics'][0]['points']);
    }

    public function testListGaugesReturnsEveryRequestedSeries(): void
    {
        $this->skipUnlessUsageStatsEnabled();

        $response = $this->call('/usage/gauges', [
            'metrics' => ['test.unknown.gauge', 'test.second.gauge'],
            'interval' => '1h',
            'aggregate' => 'max',
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(
            ['test.unknown.gauge', 'test.second.gauge'],
            array_column($response['body']['metrics'], 'metric'),
        );
        $this->assertSame([], $response['body']['metrics'][0]['points']);
        $this->assertSame([], $response['body']['metrics'][1]['points']);
    }

    public function testFlatEventAggregateNormalizesExplicitEndTime(): void
    {
        $this->skipUnlessUsageStatsEnabled();

        $response = $this->call('/usage/events', [
            'metrics' => ['test.unknown.event'],
            'endAt' => '2026-04-09T12:00:00.000Z',
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('2026-04-09T12:00:00.000+00:00', $response['body']['metrics'][0]['points'][0]['time']);
    }

    public function testUnknownMaxGaugeDoesNotFabricateZeroSeries(): void
    {
        $this->skipUnlessUsageStatsEnabled();

        $flat = $this->call('/usage/gauges', [
            'metrics' => ['test.unknown.max.gauge'],
            'aggregate' => 'max',
        ]);
        $interval = $this->call('/usage/gauges', [
            'metrics' => ['test.unknown.max.gauge'],
            'interval' => '1h',
            'aggregate' => 'max',
        ]);

        $this->assertSame(200, $flat['headers']['status-code']);
        $this->assertSame([], $flat['body']['metrics'][0]['points']);
        $this->assertSame(200, $interval['headers']['status-code']);
        $this->assertSame([], $interval['body']['metrics'][0]['points']);
    }

    public function testInvalidFilterAttributeIsRejected(): void
    {
        $this->skipUnlessUsageStatsEnabled();

        $response = $this->call('/usage/events', [
            'metrics' => ['network.requests'],
            'queries' => ['equal("osVersion", ["15"])'],
        ]);

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('general_query_invalid', $response['body']['type']);
        $this->assertNotSame(500, $response['headers']['status-code']);
    }

    public function testStructuralFilterQueryIsRejected(): void
    {
        $this->skipUnlessUsageStatsEnabled();

        $response = $this->call('/usage/events', [
            'metrics' => ['network.requests'],
            'queries' => ['limit(10)'],
        ]);

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('general_query_invalid', $response['body']['type']);
    }

    public function testDisabledStatsReturnCataloguedError(): void
    {
        if (System::getEnv('_APP_USAGE_STATS', 'enabled') !== 'disabled') {
            $this->markTestSkipped('_APP_USAGE_STATS is enabled on this stack; disabled-mode coverage needs a stack with _APP_USAGE_STATS=disabled');
        }

        $events = $this->call('/usage/events', [
            'metrics' => ['network.requests'],
        ]);
        $gauges = $this->call('/usage/gauges', [
            'metrics' => ['files.storage'],
        ]);

        $this->assertSame(403, $events['headers']['status-code']);
        $this->assertSame('general_usage_disabled', $events['body']['type']);
        $this->assertSame(403, $gauges['headers']['status-code']);
        $this->assertSame('general_usage_disabled', $gauges['body']['type']);
        $this->assertArrayHasKey('message', $events['body']);
        $this->assertNotSame('', $events['body']['message']);
    }

    public function testUsageReadScopeIsRequired(): void
    {
        $project = $this->getProject();
        $key = $this->getNewKey(['project.read']);
        $response = $this->client->call(Client::METHOD_GET, '/usage/events', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $project['$id'],
            'x-appwrite-key' => $key,
        ], [
            'metrics' => ['network.requests'],
        ]);

        $this->assertSame(401, $response['headers']['status-code']);
        $this->assertSame('general_unauthorized_scope', $response['body']['type']);
    }

    private function skipUnlessUsageStatsEnabled(): void
    {
        if (System::getEnv('_APP_USAGE_STATS', 'enabled') === 'disabled') {
            $this->markTestSkipped('Usage stats are disabled on this stack');
        }
    }

    /** @param array<string, mixed> $parameters */
    private function call(string $path, array $parameters): array
    {
        $project = $this->getProject();
        $key = $this->getNewKey(['usage.read']);

        return $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $project['$id'],
            'x-appwrite-key' => $key,
        ], $parameters);
    }
}
