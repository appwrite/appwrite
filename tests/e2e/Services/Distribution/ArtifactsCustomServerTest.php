<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Distribution;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Query;

final class ArtifactsCustomServerTest extends Scope
{
    use DistributionBase;
    use ArtifactsBase;
    use ProjectCustom;
    use SideServer;

    public function testArtifactOfAnotherProjectIsNotFound(): void
    {
        $app = $this->createApp();
        $this->assertSame(201, $app['headers']['status-code']);
        $appId = $app['body']['$id'];
        $created = $this->uploadArtifact($appId, $this->fixture(), 'app-unsigned.aab')[0];
        $this->assertSame(202, $created['headers']['status-code']);
        $artifactId = $created['body']['$id'];

        $other = $this->getProject(true);
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $other['$id'],
            'x-appwrite-key' => $other['apiKey'],
        ];

        $mirror = $this->client->call(Client::METHOD_POST, '/distribution/apps', $headers, [
            'appId' => $appId,
            'name' => 'Mirror ' . \uniqid(),
            'framework' => 'flutter',
        ]);
        $this->assertSame(201, $mirror['headers']['status-code']);

        $path = '/distribution/apps/' . $appId . '/artifacts';

        /**
         * Test for FAILURE
         */
        $artifact = $this->client->call(Client::METHOD_GET, $path . '/' . $artifactId, $headers);
        $this->assertSame(404, $artifact['headers']['status-code']);
        $this->assertSame('distribution_artifact_not_found', $artifact['body']['type']);

        $download = $this->client->call(Client::METHOD_GET, $path . '/' . $artifactId . '/download', $headers);
        $this->assertSame(404, $download['headers']['status-code']);
        $this->assertSame('distribution_artifact_not_found', $download['body']['type']);

        $deleted = $this->client->call(Client::METHOD_DELETE, $path . '/' . $artifactId, $headers);
        $this->assertSame(404, $deleted['headers']['status-code']);
        $this->assertSame('distribution_artifact_not_found', $deleted['body']['type']);

        $list = $this->client->call(Client::METHOD_GET, $path, $headers);
        $this->assertSame(200, $list['headers']['status-code']);
        $this->assertSame(0, $list['body']['total']);
        $this->assertSame([], $list['body']['artifacts']);

        $fetched = $this->getArtifact($appId, $artifactId);
        $this->assertSame(200, $fetched['headers']['status-code']);
    }

    public function testListSchedulesHidden(): void
    {
        $app = $this->createApp();
        $this->assertSame(201, $app['headers']['status-code']);
        $appId = $app['body']['$id'];
        $target = $this->createTarget($appId, ['platform' => 'android', 'identifier' => self::IDENTIFIER]);
        $this->assertSame(201, $target['headers']['status-code']);
        $created = $this->uploadArtifact($appId, $this->fixture(), 'app-unsigned.aab')[0];
        $this->assertSame(202, $created['headers']['status-code']);
        $artifactId = $created['body']['$id'];

        $artifact = $this->awaitArtifact($appId, $artifactId);
        $this->assertSame('ready', $artifact['status']);

        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ];
        $path = '/projects/' . $this->getProject()['$id'] . '/schedules';
        $hidden = [
            SCHEDULE_RESOURCE_TYPE_DISTRIBUTION_BUILD,
            SCHEDULE_RESOURCE_TYPE_DISTRIBUTION_SUBMISSION,
        ];

        /**
         * Test for SUCCESS
         */
        $schedules = $this->client->call(Client::METHOD_GET, $path, $headers);
        $this->assertSame(200, $schedules['headers']['status-code']);
        $this->assertSame([], \array_values(\array_filter(
            $schedules['body']['schedules'],
            fn (array $schedule): bool => \in_array($schedule['resourceType'], $hidden, true) || $schedule['resourceId'] === $artifactId,
        )));

        $byArtifact = $this->client->call(Client::METHOD_GET, $path, $headers, [
            'queries' => [Query::equal('resourceId', [$artifactId])->toString()],
        ]);
        $this->assertSame(200, $byArtifact['headers']['status-code']);
        $this->assertSame(0, $byArtifact['body']['total']);
        $this->assertSame([], $byArtifact['body']['schedules']);

        foreach ($hidden as $type) {
            $byType = $this->client->call(Client::METHOD_GET, $path, $headers, [
                'queries' => [Query::equal('resourceType', [$type])->toString()],
            ]);
            $this->assertSame(200, $byType['headers']['status-code']);
            $this->assertSame(0, $byType['body']['total'], $type . ' schedules must not be listed');
            $this->assertSame([], $byType['body']['schedules']);
        }
    }
}
