<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Usage;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Tests\E2E\Services\Functions\FunctionsBase;
use Utopia\Database\Helpers\ID;
use Utopia\System\System;

final class UsageDeploymentsCustomServerTest extends Scope
{
    use FunctionsBase;
    use ProjectCustom;
    use SideServer;

    public function testDeploymentUsageIsAttributedToItsFunction(): void
    {
        if (System::getEnv('_APP_USAGE_STATS', 'enabled') === 'disabled') {
            $this->markTestSkipped('Usage stats are disabled on this stack');
        }

        self::$project = $this->getProject(true);
        $project = $this->getProject();
        $healthKey = $this->getNewKey(['health.read']);

        $this->assertEventually(function () use ($project, $healthKey) {
            $response = $this->client->call(Client::METHOD_GET, '/health/usage', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $project['$id'],
                'x-appwrite-key' => $healthKey,
            ]);

            $this->assertSame(200, $response['headers']['status-code'], 'Usage storage must be ready before the deployment is created');
        }, 60_000, 500);

        $function = $this->createFunction([
            'functionId' => ID::unique(),
            'name' => 'Deployment usage attribution',
            'runtime' => 'node-22',
            'entrypoint' => 'index.js',
        ]);
        $this->assertSame(201, $function['headers']['status-code']);

        $deployment = $this->createDeployment($function['body']['$id'], [
            'code' => $this->packageFunction('basic'),
            'activate' => 'false',
        ]);
        $this->assertSame(202, $deployment['headers']['status-code']);

        $usageHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $project['$id'],
            'x-appwrite-key' => $this->getNewKey(['usage.read']),
        ];

        $this->assertEventually(function () use ($usageHeaders) {
            $response = $this->client->call(Client::METHOD_GET, '/usage/events', $usageHeaders, [
                'metrics' => ['functions.deployments'],
                'dimensions' => ['resourceType'],
            ]);

            $this->assertSame(200, $response['headers']['status-code']);
            $points = $response['body']['metrics'][0]['points'];
            $this->assertSame(['function'], \array_column($points, 'resourceType'), 'Deployment usage must be attributed to the owning function, not the project');
            $this->assertSame(1, (int) $points[0]['value']);
        }, 60_000, 500);
    }
}
