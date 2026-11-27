<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Distribution;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Tests\E2E\Services\VCSGitea\VCSGiteaBase;
use Utopia\System\System;

final class BuildsCustomServerTest extends Scope
{
    use DistributionBase;
    use BuildsBase;
    use GiteaRepositories;
    use VCSGiteaBase;
    use ProjectCustom;
    use SideServer;

    private const int RECONCILE = 150_000;

    public function testCreateBuildRejectsInvalidInput(): void
    {
        $prepared = $this->prepareBuild(target: ['versionStrategy' => 'manual']);
        $appId = $prepared['appId'];

        /**
         * Test for FAILURE
         */
        $target = $this->createBuild($appId, ['targetId' => 'missing-' . \uniqid()]);
        $this->assertSame(404, $target['headers']['status-code']);
        $this->assertSame('distribution_target_not_found', $target['body']['type']);

        $manual = $this->createBuild($appId, ['targetId' => $prepared['targetId']]);
        $this->assertSame(400, $manual['headers']['status-code']);
        $this->assertSame('distribution_version_invalid', $manual['body']['type']);

        $range = $this->createBuild($appId, [
            'targetId' => $prepared['targetId'],
            'versionCode' => 0,
        ]);
        $this->assertSame(400, $range['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $range['body']['type']);

        $foreign = $this->createApp();
        $this->assertSame(201, $foreign['headers']['status-code']);
        $foreignTarget = $this->createTarget($foreign['body']['$id']);
        $this->assertSame(201, $foreignTarget['headers']['status-code']);
        $crossed = $this->createBuild($appId, ['targetId' => $foreignTarget['body']['$id']]);
        $this->assertSame(404, $crossed['headers']['status-code'], 'A target of another app is not found.');
        $this->assertSame('distribution_target_not_found', $crossed['body']['type']);

        $this->assertSame(0, $this->listBuilds($appId)['body']['total']);

        $disabled = $this->prepareBuild(['enabled' => false]);
        $refused = $this->createBuild($disabled['appId'], ['targetId' => $disabled['targetId']]);
        $this->assertSame(400, $refused['headers']['status-code'], 'A disabled app rejects new builds.');
        $this->assertSame('general_argument_invalid', $refused['body']['type']);
        $this->assertSame(0, $this->listBuilds($disabled['appId'])['body']['total']);

        /**
         * Test for SUCCESS
         */
        $versioned = $this->createBuild($appId, [
            'targetId' => $prepared['targetId'],
            'versionCode' => 42,
        ]);
        $this->assertSame(202, $versioned['headers']['status-code'], \json_encode($versioned['body']));
        $this->assertSame(42, $versioned['body']['versionCode']);

        $ready = $this->awaitBuild($appId, $versioned['body']['$id'], ['ready']);
        $artifact = $this->client->call(Client::METHOD_GET, '/distribution/apps/' . $appId . '/artifacts/' . $ready['artifactId'], $this->headers());
        $this->assertSame(200, $artifact['headers']['status-code']);
        $this->assertSame(42, $artifact['body']['versionCode']);
    }

    public function testIosBuildFailsWithoutRunners(): void
    {
        $prepared = $this->prepareBuild(target: [
            'platform' => 'ios',
            'track' => 'testflight',
        ]);
        $appId = $prepared['appId'];

        $created = $this->createBuild($appId, ['targetId' => $prepared['targetId']]);
        $this->assertSame(202, $created['headers']['status-code'], \json_encode($created['body']));
        $this->assertSame('ios', $created['body']['platform']);
        $this->assertSame(0, $created['body']['versionCode']);
        $this->assertSame('1', $created['body']['buildNumber']);
        $buildId = $created['body']['$id'];

        /**
         * Test for FAILURE
         */
        $failed = $this->awaitBuild($appId, $buildId, ['failed'], self::RECONCILE);
        $this->assertSame('distribution_runner_unavailable', $failed['errorCode']);
        $this->assertNotEmpty($failed['errorMessage']);
        $this->assertNotEmpty($failed['endedAt']);
        $this->assertSame('', $failed['artifactId']);

        $terminal = $this->updateBuildStatus($appId, $buildId, 'canceled');
        $this->assertSame(409, $terminal['headers']['status-code']);
        $this->assertSame('distribution_build_transition_invalid', $terminal['body']['type']);

        $this->assertEventually(function () use ($appId, $buildId): void {
            $app = $this->client->call(Client::METHOD_GET, '/distribution/apps/' . $appId, $this->headers());
            $this->assertSame(200, $app['headers']['status-code']);
            $this->assertSame($buildId, $app['body']['latestBuildId']);
            $this->assertSame('failed', $app['body']['latestBuildStatus'], 'The worker settles an iOS build that no runner can take.');
        }, self::RECONCILE, 2000);

        if (System::getEnv('_APP_USAGE_STATS', 'enabled') === 'disabled') {
            return;
        }

        $metered = [
            METRIC_DISTRIBUTION_BUILDS => 1,
            METRIC_DISTRIBUTION_BUILDS_SUCCESS => 0,
            METRIC_DISTRIBUTION_BUILDS_FAILED => 1,
        ];
        $this->assertUsage($appId, $metered);
        $this->assertUsageStays($appId, $metered);
    }
}
