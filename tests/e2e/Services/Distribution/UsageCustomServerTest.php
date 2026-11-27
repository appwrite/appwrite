<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Distribution;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Query;
use Utopia\System\System;

final class UsageCustomServerTest extends Scope
{
    use DistributionBase;
    use MockStores;
    use ProjectCustom;
    use SideServer;

    private const int METERED = 15;

    public function testListUsageEventsForSubmission(): void
    {
        if (System::getEnv('_APP_USAGE_STATS', 'enabled') === 'disabled') {
            $this->markTestSkipped('Usage stats are disabled on this stack');
        }

        $prepared = $this->prepareGoogleRelease(self::METERED);
        $appId = $prepared['appId'];

        $created = $this->createSubmission($appId, [
            'releaseId' => $prepared['releaseId'],
            'targetId' => $prepared['targetId'],
            'artifactId' => $prepared['artifactId'],
            'track' => 'internal',
        ]);
        $this->assertSame(201, $created['headers']['status-code']);
        $this->awaitSubmission($appId, $created['body']['$id'], 'published');

        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getNewKey(['usage.read']),
        ];
        $expected = [
            METRIC_DISTRIBUTION_ARTIFACTS_INBOUND => $prepared['size'],
            METRIC_DISTRIBUTION_SIGNING => 1,
            METRIC_DISTRIBUTION_SUBMISSIONS => 1,
            METRIC_DISTRIBUTION_SUBMISSIONS_SUCCESS => 1,
            METRIC_DISTRIBUTION_SUBMISSIONS_FAILED => 0,
        ];

        /**
         * Test for SUCCESS
         */
        $this->assertEventually(function () use ($headers, $appId, $expected): void {
            $usage = $this->client->call(Client::METHOD_GET, '/usage/events', $headers, [
                'metrics' => \array_keys($expected),
                'queries' => [Query::equal('resourceId', [$appId])->toString()],
            ]);
            $this->assertSame(200, $usage['headers']['status-code']);

            $totals = \array_fill_keys(\array_keys($expected), 0);
            foreach ($usage['body']['metrics'] as $metric) {
                if (\array_key_exists($metric['metric'], $totals)) {
                    $totals[$metric['metric']] += (int) \array_sum(\array_column($metric['points'], 'value'));
                }
            }

            foreach ($expected as $key => $value) {
                $this->assertSame($value, $totals[$key], "Metric {$key} total");
            }
        }, 120_000, 1000);

        /**
         * Test for FAILURE
         */
        $denied = $this->client->call(Client::METHOD_GET, '/usage/events', [
            ...$headers,
            'x-appwrite-key' => $this->getNewKey(['distribution.read']),
        ], [
            'metrics' => [METRIC_DISTRIBUTION_SUBMISSIONS],
        ]);
        $this->assertSame(401, $denied['headers']['status-code']);
        $this->assertSame('general_unauthorized_scope', $denied['body']['type']);
    }
}
