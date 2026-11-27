<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Distribution;

use Appwrite\ID;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\System\System;

final class EventsCustomServerTest extends Scope
{
    use DistributionBase;
    use ProjectCustom;
    use SideServer;

    private const string FIXTURE = __DIR__ . '/../../../resources/distribution/app-unsigned.aab';

    private const string EXIT = 'orchestrator.job.exit';

    private const string STAGE = 'inspect';

    private const int FAILED = 4;

    private const int STALE = 901;

    public function testCreateEventRefusesBadSignature(): void
    {
        $projectId = $this->getProject()['$id'];
        $event = $this->exitEvent($projectId, ID::unique(), ID::unique());
        $body = (string) \json_encode($event);

        /**
         * Test for FAILURE
         */
        $unsigned = $this->postEvent($projectId, $event, '');
        $this->assertSame(401, $unsigned['headers']['status-code']);
        $this->assertSame('user_unauthorized', $unsigned['body']['type']);

        $forged = $this->postEvent($projectId, $event, 'sha256=' . \hash_hmac('sha256', $body, 'not-the-callback-secret'));
        $this->assertSame(401, $forged['headers']['status-code']);
        $this->assertSame('user_unauthorized', $forged['body']['type']);

        $unprefixed = $this->postEvent($projectId, $event, \hash_hmac('sha256', $body, $this->secret()));
        $this->assertSame(401, $unprefixed['headers']['status-code']);
        $this->assertSame('user_unauthorized', $unprefixed['body']['type']);

        $tampered = $event;
        $tampered['data']['exitCode'] = 0;
        $swapped = $this->postEvent($projectId, $tampered, $this->sign($event));
        $this->assertSame(401, $swapped['headers']['status-code']);
        $this->assertSame('user_unauthorized', $swapped['body']['type']);
    }

    public function testCreateEventAcknowledgesInapplicableEvent(): void
    {
        $projectId = $this->getProject()['$id'];

        $events = [
            'stale' => $this->exitEvent($projectId, ID::unique(), ID::unique(), \time() - self::STALE),
            'future' => $this->exitEvent($projectId, ID::unique(), ID::unique(), \time() + self::STALE),
            'foreign' => $this->exitEvent($this->getProject(true)['$id'], ID::unique(), ID::unique()),
            'unknown stage' => $this->exitEvent($projectId, ID::unique(), ID::unique(), stage: 'deploy'),
            'malformed' => ['type' => self::EXIT, 'data' => ['meta' => ['projectId' => $projectId, 'stage' => self::STAGE]]],
        ];

        /**
         * Test for SUCCESS
         */
        foreach ($events as $case => $event) {
            $acknowledged = $this->postEvent($projectId, $event, $this->sign($event));
            $this->assertSame(204, $acknowledged['headers']['status-code'], $case);
            $this->assertEmpty($acknowledged['body'], $case);
        }
    }

    public function testCreateEventAcknowledgesReplay(): void
    {
        $projectId = $this->getProject()['$id'];
        $event = $this->exitEvent($projectId, ID::unique(), ID::unique());
        $signature = $this->sign($event);

        /**
         * Test for SUCCESS
         */
        foreach (['first', 'replayed'] as $case) {
            $acknowledged = $this->postEvent($projectId, $event, $signature);
            $this->assertSame(204, $acknowledged['headers']['status-code'], $case);
            $this->assertEmpty($acknowledged['body'], $case);
        }
    }

    public function testGetBuildSigningRefusesBadToken(): void
    {
        $projectId = $this->getProject()['$id'];
        $app = $this->createApp();
        $this->assertSame(201, $app['headers']['status-code']);
        $appId = $app['body']['$id'];
        $artifactId = $this->uploadFixture($appId);
        $path = '/distribution/apps/' . $appId . '/builds/' . $artifactId . '/signing';

        /**
         * Test for FAILURE
         */
        $missing = $this->client->call(Client::METHOD_GET, $path, ['content-type' => 'application/json'], ['project' => $projectId]);
        $this->assertSame(401, $missing['headers']['status-code']);
        $this->assertArrayNotHasKey('keystore', $missing['body']);

        $guessed = $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
            'authorization' => 'Bearer ' . \bin2hex(\random_bytes(32)),
        ], ['project' => $projectId]);
        $this->assertSame(401, $guessed['headers']['status-code']);
        $this->assertArrayNotHasKey('keystore', $guessed['body']);

        $basic = $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
            'authorization' => 'Basic ' . \base64_encode('signing:' . \bin2hex(\random_bytes(32))),
        ], ['project' => $projectId]);
        $this->assertSame(401, $basic['headers']['status-code']);

        $unknown = $this->client->call(Client::METHOD_GET, '/distribution/apps/' . $appId . '/builds/' . ID::unique() . '/signing', [
            'content-type' => 'application/json',
            'authorization' => 'Bearer ' . \bin2hex(\random_bytes(32)),
        ], ['project' => $projectId]);
        $this->assertSame(401, $unknown['headers']['status-code']);
    }

    private function uploadFixture(string $appId): string
    {
        $bundle = \file_get_contents(self::FIXTURE);
        $this->assertIsString($bundle);

        $created = $this->client->call(Client::METHOD_POST, '/distribution/apps/' . $appId . '/artifacts', \array_merge($this->headers(), [
            'content-type' => 'multipart/form-data',
        ]), [
            'file' => new \CURLFile('data://application/octet-stream;base64,' . \base64_encode($bundle), 'application/octet-stream', 'app-unsigned.aab'),
        ]);
        $this->assertSame(202, $created['headers']['status-code']);
        $this->assertSame('processing', $created['body']['status']);

        return $created['body']['$id'];
    }

    /**
     * @return array<string, mixed>
     */
    private function exitEvent(string $projectId, string $appId, string $artifactId, ?int $time = null, string $stage = self::STAGE): array
    {
        $jobId = 'dist-' . \bin2hex(\random_bytes(20));

        return [
            'specversion' => '1.0',
            'type' => self::EXIT,
            'source' => '/orchestrator',
            'subject' => $jobId,
            'id' => $jobId . '-' . \hrtime(true),
            'time' => \gmdate('Y-m-d\TH:i:s\Z', $time ?? \time()),
            'datacontenttype' => 'application/json',
            'data' => [
                'jobId' => $jobId,
                'exitCode' => self::FAILED,
                'reason' => null,
                'image' => 'appwrite/distribution-tools:test',
                'durationSeconds' => 1.0,
                'meta' => [
                    'projectId' => $projectId,
                    'appId' => $appId,
                    'artifactId' => $artifactId,
                    'stage' => $stage,
                    'attempt' => '1',
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    private function postEvent(string $projectId, array $event, string $signature): array
    {
        $headers = ['content-type' => 'application/json'];
        if ($signature !== '') {
            $headers['x-signature-256'] = $signature;
        }

        return $this->client->call(Client::METHOD_POST, '/distribution/events?project=' . $projectId, $headers, $event);
    }

    /**
     * @param array<string, mixed> $event
     */
    private function sign(array $event): string
    {
        return 'sha256=' . \hash_hmac('sha256', (string) \json_encode($event), $this->secret());
    }

    private function secret(): string
    {
        $secret = System::getEnv('_APP_DISTRIBUTION_SIGNING_CALLBACK_SECRET', '')
            ?: System::getEnv('_APP_JOBS_SECRET', '');
        $this->assertNotSame('', $secret, 'The callback secret must be set in the test container.');

        return $secret;
    }
}
