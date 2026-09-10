<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Usage;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;
use Utopia\System\System;

final class UsageCustomServerTest extends Scope
{
    use ProjectCustom;
    use SideServer;

    public function testListEventsReturnsRequestedEmptySeries(): void
    {
        $this->waitForUsageStats();

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
        $this->waitForUsageStats();

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
        $this->waitForUsageStats();

        $response = $this->call('/usage/events', [
            'metrics' => ['test.unknown.event'],
            'endAt' => '2026-04-09T12:00:00.000Z',
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('2026-04-09T12:00:00.000+00:00', $response['body']['metrics'][0]['points'][0]['time']);
    }

    public function testUnknownMaxGaugeDoesNotFabricateZeroSeries(): void
    {
        $this->waitForUsageStats();

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
        $this->waitForUsageStats();

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
        $this->waitForUsageStats();

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

    public static function databaseApis(): \Iterator
    {
        yield 'documents' => ['databases', 'collections', 'documents', 'attributes', 'collectionId', 'documentId'];
        yield 'rows' => ['tablesdb', 'tables', 'rows', 'columns', 'tableId', 'rowId'];
    }

    #[DataProvider('databaseApis')]
    public function testDatabaseOperationResourceId(string $api, string $containers, string $records, string $attributes, string $containerIdKey, string $recordIdKey): void
    {
        self::$project = $this->getProject(true);
        $this->waitForUsageStats();
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];
        $databaseIds = [ID::unique(), ID::unique()];
        $containerId = ID::unique();
        $paths = [];

        // Test for SUCCESS: each API shares the database operation emitters.
        foreach ($databaseIds as $databaseId) {
            $response = $this->client->call(Client::METHOD_POST, "/$api", $headers, [
                'databaseId' => $databaseId,
                'name' => 'Usage attribution ' . $databaseId,
            ]);
            $this->assertSame(201, $response['headers']['status-code']);
            $path = "/$api/$databaseId/$containers/$containerId";
            $response = $this->client->call(Client::METHOD_POST, "/$api/$databaseId/$containers", $headers, [
                $containerIdKey => $containerId,
                'name' => 'Usage attribution',
            ]);
            $this->assertSame(201, $response['headers']['status-code']);
            $response = $this->client->call(Client::METHOD_POST, "$path/$attributes/integer", $headers, [
                'key' => 'count',
                'required' => true,
            ]);
            $this->assertSame(202, $response['headers']['status-code']);
            $this->assertEventually(function () use ($path, $attributes, $headers) {
                $response = $this->client->call(Client::METHOD_GET, "$path/$attributes/count", $headers);
                $this->assertSame(200, $response['headers']['status-code']);
                $this->assertSame('available', $response['body']['status']);
            });
            $paths[] = "$path/$records";
        }

        $response = $this->client->call(Client::METHOD_GET, $paths[0], $headers);
        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(0, $response['body']['total']);
        foreach ($paths as $path) {
            $response = $this->client->call(Client::METHOD_POST, $path, $headers, [
                $recordIdKey => 'single',
                'data' => ['count' => 1],
            ]);
            $this->assertSame(201, $response['headers']['status-code']);
            $response = $this->client->call(Client::METHOD_GET, "$path/single", $headers);
            $this->assertSame(200, $response['headers']['status-code']);
            $this->assertSame(1, $response['body']['count']);
        }
        $response = $this->client->call(Client::METHOD_GET, $paths[0], $headers);
        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(1, $response['body']['total']);

        foreach ([Client::METHOD_PATCH, Client::METHOD_PUT] as $method) {
            $response = $this->client->call($method, "$paths[0]/single", $headers, ['data' => ['count' => 2]]);
            $this->assertSame(200, $response['headers']['status-code']);
        }
        foreach (['increment', 'decrement'] as $operation) {
            $response = $this->client->call(Client::METHOD_PATCH, "$paths[0]/single/count/$operation", $headers, ['value' => 1]);
            $this->assertSame(200, $response['headers']['status-code']);
            $this->assertSame($operation === 'increment' ? 3 : 2, $response['body']['count']);
        }
        $bulk = [
            ['$id' => 'bulk-a', 'count' => 1],
            ['$id' => 'bulk-b', 'count' => 1],
        ];
        $response = $this->client->call(Client::METHOD_POST, $paths[0], $headers, [$records => $bulk]);
        $this->assertSame(201, $response['headers']['status-code']);
        $this->assertSame(2, $response['body']['total']);
        $queries = [Query::equal('$id', ['bulk-a', 'bulk-b'])->toString()];
        $response = $this->client->call(Client::METHOD_PATCH, $paths[0], $headers, [
            'queries' => $queries,
            'data' => ['count' => 2],
        ]);
        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(2, $response['body']['total']);
        $response = $this->client->call(Client::METHOD_PUT, $paths[0], $headers, [$records => $bulk]);
        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(2, $response['body']['total']);
        $response = $this->client->call(Client::METHOD_DELETE, "$paths[0]/single", $headers);
        $this->assertSame(204, $response['headers']['status-code']);
        $response = $this->client->call(Client::METHOD_DELETE, $paths[0], $headers, ['queries' => $queries]);
        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(2, $response['body']['total']);

        // A single commit must retain the public ID of each database group.
        $response = $this->client->call(Client::METHOD_POST, "/$api/transactions", $headers);
        $this->assertSame(201, $response['headers']['status-code']);
        $transactionId = $response['body']['$id'];
        $operations = [];
        foreach ([$databaseIds[0], $databaseIds[1], $databaseIds[1]] as $index => $databaseId) {
            $operations[] = [
                'databaseId' => $databaseId,
                $containerIdKey => $containerId,
                $recordIdKey => 'transaction-' . $index,
                'action' => 'create',
                'data' => ['count' => 1],
            ];
        }
        $response = $this->client->call(Client::METHOD_POST, "/$api/transactions/$transactionId/operations", $headers, ['operations' => $operations]);
        $this->assertSame(201, $response['headers']['status-code']);
        $response = $this->client->call(Client::METHOD_PATCH, "/$api/transactions/$transactionId", $headers, ['commit' => true]);
        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('committed', $response['body']['status']);

        // Project totals are unchanged; filtering must neither lose nor mix databases.
        $expected = [
            '' => [4, 18],
            $databaseIds[0] => [3, 15],
            $databaseIds[1] => [1, 3],
            $this->getProject()['$id'] => [0, 0],
            ID::unique() => [0, 0],
        ];
        $usageHeaders = array_merge($headers, ['x-appwrite-key' => $this->getNewKey(['usage.read'])]);
        foreach ($expected as $resourceId => $values) {
            $this->assertEventually(function () use ($resourceId, $values, $usageHeaders) {
                $response = $this->client->call(Client::METHOD_GET, '/usage/events', $usageHeaders, [
                    'metrics' => ['databases.operations.reads', 'databases.operations.writes'],
                    'queries' => $resourceId === '' ? [] : [Query::equal('resourceId', [$resourceId])->toString()],
                ]);
                $this->assertSame(200, $response['headers']['status-code']);
                foreach ($values as $index => $value) {
                    $this->assertEquals($value, array_sum(array_column($response['body']['metrics'][$index]['points'], 'value')), "Resource '$resourceId': " . $response['body']['metrics'][$index]['metric']);
                }
            }, 60_000, 500);
        }
    }

    private function waitForUsageStats(): void
    {
        if (System::getEnv('_APP_USAGE_STATS', 'enabled') === 'disabled') {
            $this->markTestSkipped('Usage stats are disabled on this stack');
        }

        $project = $this->getProject();
        $key = $this->getNewKey(['health.read']);

        // HTTP health can pass while the ClickHouse usage schema is still initializing.
        $this->assertEventually(function () use ($project, $key) {
            $response = $this->client->call(Client::METHOD_GET, '/health/usage', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $project['$id'],
                'x-appwrite-key' => $key,
            ]);

            $this->assertSame(200, $response['headers']['status-code'], 'Usage storage must be ready before testing usage queries');
        }, 60_000, 500);
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
