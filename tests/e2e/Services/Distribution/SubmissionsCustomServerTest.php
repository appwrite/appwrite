<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Distribution;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Query;

final class SubmissionsCustomServerTest extends Scope
{
    use DistributionBase;
    use MockStores;
    use SubmissionsBase;
    use ProjectCustom;
    use SideServer;

    private const int PUBLISHED = 1;
    private const int IDEMPOTENT = 2;
    private const int REUSED = 3;
    private const int CONTROLLED = 4;
    private const int IN_USE = 5;
    private const int REFUSED = 7;
    private const int ROLLED_OUT = 9;
    private const int CANCELED = 10;
    private const int STALL_SECONDS = 25;
    private const float FRACTION = 0.5;

    public function testCreateSubmissionPublished(): void
    {
        $prepared = $this->prepareGoogleRelease(self::PUBLISHED);
        $appId = $prepared['appId'];
        $before = $this->googleUploads($prepared['identifier']);

        $created = $this->createSubmission($appId, [
            'releaseId' => $prepared['releaseId'],
            'targetId' => $prepared['targetId'],
            'artifactId' => $prepared['artifactId'],
            'track' => 'internal',
        ]);

        /**
         * Test for SUCCESS
         */
        $this->assertSame(201, $created['headers']['status-code']);
        $submissionId = $created['body']['$id'];
        $this->assertSame($appId, $created['body']['appId']);
        $this->assertSame($prepared['releaseId'], $created['body']['releaseId']);
        $this->assertSame($prepared['targetId'], $created['body']['targetId']);
        $this->assertSame($prepared['artifactId'], $created['body']['artifactId']);
        $this->assertSame('android', $created['body']['platform']);
        $this->assertSame('google', $created['body']['provider']);
        $this->assertSame('internal', $created['body']['track']);
        $this->assertEquals(1, $created['body']['rollout']);
        $this->assertTrue($created['body']['automatic']);
        $this->assertSame($prepared['targetId'] . ':internal:1', $created['body']['key']);
        $this->assertContains($created['body']['status'], ['waiting', 'validating', 'uploading']);

        $published = $this->awaitSubmission($appId, $submissionId, 'published');
        $this->assertSame('settled', $published['step']);
        $this->assertNotEmpty($published['publishedAt']);
        $this->assertSame('', $published['errorCode']);

        $release = $this->getRelease($appId, $prepared['releaseId']);
        $this->assertSame(200, $release['headers']['status-code']);
        $this->assertSame('active', $release['body']['status'], 'A release published only on an internal track stays active.');

        $after = $this->googleUploads($prepared['identifier']);
        $this->assertSame($before['sessions'] + 1, $after['sessions']);
        $this->assertSame($before['completed'] + 1, $after['completed']);
        $this->assertSame($before['bytes'] + $prepared['signedSize'], $after['bytes']);

        $listed = $this->listSubmissions($appId, [Query::equal('releaseId', [$prepared['releaseId']])->toString()]);
        $this->assertSame(200, $listed['headers']['status-code']);
        $this->assertSame(1, $listed['body']['total']);
        $this->assertSame([$submissionId], \array_column($listed['body']['submissions'], '$id'));

        $publishedOnly = $this->listSubmissions($appId, [Query::equal('status', ['published'])->toString()]);
        $this->assertSame(200, $publishedOnly['headers']['status-code']);
        $this->assertSame([$submissionId], \array_column($publishedOnly['body']['submissions'], '$id'));

        /**
         * Test for FAILURE
         */
        $other = $this->getProject(true);
        $foreign = $this->client->call(Client::METHOD_GET, '/distribution/apps/' . $appId . '/submissions/' . $submissionId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $other['$id'],
            'x-appwrite-key' => $other['apiKey'],
        ]);
        $this->assertSame(404, $foreign['headers']['status-code']);
    }

    public function testCreateSubmissionIdempotent(): void
    {
        $prepared = $this->prepareGoogleRelease(self::IDEMPOTENT);
        $appId = $prepared['appId'];
        $this->stallGoogleUpload($prepared['identifier'], self::STALL_SECONDS);
        $params = [
            'releaseId' => $prepared['releaseId'],
            'targetId' => $prepared['targetId'],
            'artifactId' => $prepared['artifactId'],
            'track' => 'internal',
            'key' => 'e2e-' . \uniqid(),
        ];

        /**
         * Test for SUCCESS
         */
        $created = $this->createSubmission($appId, $params);
        $this->assertSame(201, $created['headers']['status-code']);
        $this->assertSame($params['key'], $created['body']['key']);
        $submissionId = $created['body']['$id'];

        $repeated = $this->createSubmission($appId, $params);
        $this->assertSame(200, $repeated['headers']['status-code']);
        $this->assertSame($submissionId, $repeated['body']['$id']);
        $this->assertSame($created['body']['$createdAt'], $repeated['body']['$createdAt']);

        $listed = $this->listSubmissions($appId);
        $this->assertSame(200, $listed['headers']['status-code']);
        $this->assertSame(1, $listed['body']['total']);

        $updated = $this->updateSubmission($appId, $submissionId, ['automatic' => false]);
        $this->assertSame(200, $updated['headers']['status-code']);
        $this->assertSame($submissionId, $updated['body']['$id']);
        $this->assertFalse($updated['body']['automatic']);
        $this->assertFalse($this->getSubmission($appId, $submissionId)['body']['automatic']);

        $this->awaitSubmission($appId, $submissionId, 'published');

        $settled = $this->createSubmission($appId, $params);
        $this->assertSame(200, $settled['headers']['status-code']);
        $this->assertSame($submissionId, $settled['body']['$id']);
        $this->assertSame('published', $settled['body']['status']);
    }

    public function testCreateSubmissionVersionReused(): void
    {
        $prepared = $this->prepareGoogleRelease(self::REUSED);
        $appId = $prepared['appId'];
        $params = [
            'releaseId' => $prepared['releaseId'],
            'targetId' => $prepared['targetId'],
            'artifactId' => $prepared['artifactId'],
            'track' => 'internal',
        ];

        $created = $this->createSubmission($appId, $params);
        $this->assertSame(201, $created['headers']['status-code']);

        /**
         * Test for FAILURE
         */
        $reused = $this->createSubmission($appId, [...$params, 'key' => 'e2e-' . \uniqid()]);
        $this->assertSame(400, $reused['headers']['status-code']);
        $this->assertSame('distribution_version_invalid', $reused['body']['type']);

        $release = $this->createRelease($appId);
        $this->assertSame(201, $release['headers']['status-code']);
        $elsewhere = $this->createSubmission($appId, [...$params, 'releaseId' => $release['body']['$id'], 'key' => 'e2e-' . \uniqid()]);
        $this->assertSame(400, $elsewhere['headers']['status-code']);
        $this->assertSame('distribution_version_invalid', $elsewhere['body']['type']);

        $this->awaitSubmission($appId, $created['body']['$id'], 'published');

        $afterPublish = $this->createSubmission($appId, [...$params, 'key' => 'e2e-' . \uniqid()]);
        $this->assertSame(400, $afterPublish['headers']['status-code']);
        $this->assertSame('distribution_version_invalid', $afterPublish['body']['type']);

        $listed = $this->listSubmissions($appId);
        $this->assertSame(200, $listed['headers']['status-code']);
        $this->assertSame(1, $listed['body']['total']);
    }

    public function testUpdateSubmissionStatusTransitions(): void
    {
        $prepared = $this->prepareGoogleRelease(self::CONTROLLED);
        $appId = $prepared['appId'];
        $this->stageGoogleRollout($prepared['identifier'], 'internal', self::FRACTION);

        $created = $this->createSubmission($appId, [
            'releaseId' => $prepared['releaseId'],
            'targetId' => $prepared['targetId'],
            'artifactId' => $prepared['artifactId'],
            'track' => 'internal',
        ]);
        $this->assertSame(201, $created['headers']['status-code']);
        $submissionId = $created['body']['$id'];

        $staged = $this->awaitSubmission($appId, $submissionId, 'published');
        $this->assertEquals(self::FRACTION, $staged['rollout']);
        $this->assertSame('released', $staged['step']);

        /**
         * Test for FAILURE
         */
        foreach (['canceled', 'published'] as $status) {
            $refused = $this->updateSubmissionStatus($appId, $submissionId, $status);
            $this->assertSame(409, $refused['headers']['status-code'], 'staged published to ' . $status);
            $this->assertSame('distribution_submission_transition_invalid', $refused['body']['type'], 'staged published to ' . $status);
        }

        /**
         * Test for SUCCESS
         */
        $halt = $this->updateSubmissionStatus($appId, $submissionId, 'halted');
        $this->assertSame(200, $halt['headers']['status-code']);
        $this->assertSame($submissionId, $halt['body']['$id']);

        $halted = $this->awaitSubmission($appId, $submissionId, 'halted', 120_000);
        $this->assertSame('', $halted['errorCode']);
        $this->assertEquals(self::FRACTION, $halted['rollout']);

        $release = $this->getRelease($appId, $prepared['releaseId']);
        $this->assertSame(200, $release['headers']['status-code']);
        $this->assertSame('active', $release['body']['status']);

        /**
         * Test for FAILURE
         */
        foreach (['halted', 'canceled'] as $status) {
            $refused = $this->updateSubmissionStatus($appId, $submissionId, $status);
            $this->assertSame(409, $refused['headers']['status-code'], 'halted to ' . $status);
            $this->assertSame('distribution_submission_transition_invalid', $refused['body']['type'], 'halted to ' . $status);
        }

        /**
         * Test for SUCCESS
         */
        $resume = $this->updateSubmissionStatus($appId, $submissionId, 'published');
        $this->assertSame(200, $resume['headers']['status-code']);

        $resumed = $this->awaitSubmission($appId, $submissionId, 'published', 120_000);
        $this->assertSame('', $resumed['errorCode']);
        $this->assertEquals(self::FRACTION, $resumed['rollout']);

        $active = $this->getRelease($appId, $prepared['releaseId']);
        $this->assertSame(200, $active['headers']['status-code']);
        $this->assertSame('active', $active['body']['status'], 'A release published only on an internal track stays active.');
    }

    public function testUpdateSubmissionStatusRolledOut(): void
    {
        $prepared = $this->prepareGoogleRelease(self::ROLLED_OUT);
        $appId = $prepared['appId'];

        $created = $this->createSubmission($appId, [
            'releaseId' => $prepared['releaseId'],
            'targetId' => $prepared['targetId'],
            'artifactId' => $prepared['artifactId'],
            'track' => 'internal',
        ]);
        $this->assertSame(201, $created['headers']['status-code']);
        $submissionId = $created['body']['$id'];

        $published = $this->awaitSubmission($appId, $submissionId, 'published');
        $this->assertEquals(1, $published['rollout']);
        $this->assertSame('settled', $published['step']);

        /**
         * Test for FAILURE
         */
        foreach (['halted', 'canceled', 'published'] as $status) {
            $refused = $this->updateSubmissionStatus($appId, $submissionId, $status);
            $this->assertSame(409, $refused['headers']['status-code'], 'rolled out to ' . $status);
            $this->assertSame('distribution_submission_transition_invalid', $refused['body']['type'], 'rolled out to ' . $status);
        }

        $unchanged = $this->getSubmission($appId, $submissionId);
        $this->assertSame(200, $unchanged['headers']['status-code']);
        $this->assertSame('published', $unchanged['body']['status']);
        $this->assertEquals(1, $unchanged['body']['rollout']);
    }

    public function testUpdateSubmissionStatusCanceled(): void
    {
        $prepared = $this->prepareGoogleRelease(self::CANCELED);
        $appId = $prepared['appId'];
        $before = $this->googleUploads($prepared['identifier']);
        $this->stallGoogleUpload($prepared['identifier'], self::STALL_SECONDS);

        $created = $this->createSubmission($appId, [
            'releaseId' => $prepared['releaseId'],
            'targetId' => $prepared['targetId'],
            'artifactId' => $prepared['artifactId'],
            'track' => 'internal',
        ]);
        $this->assertSame(201, $created['headers']['status-code']);
        $submissionId = $created['body']['$id'];

        $this->assertEventually(function () use ($prepared, $before): void {
            $this->assertSame($before['completed'] + 1, $this->googleUploads($prepared['identifier'])['completed']);
        }, 180_000, 250);

        $uploading = $this->getSubmission($appId, $submissionId);
        $this->assertSame(200, $uploading['headers']['status-code']);
        $this->assertSame('uploading', $uploading['body']['status']);

        /**
         * Test for SUCCESS
         */
        $cancel = $this->updateSubmissionStatus($appId, $submissionId, 'canceled');
        $this->assertSame(200, $cancel['headers']['status-code']);
        $this->assertSame($submissionId, $cancel['body']['$id']);

        $canceled = $this->awaitSubmission($appId, $submissionId, 'canceled', 60_000);
        $this->assertNotContains($canceled['step'], ['released', 'settled']);

        /**
         * Test for FAILURE
         */
        foreach (['canceled', 'halted', 'published'] as $status) {
            $refused = $this->updateSubmissionStatus($appId, $submissionId, $status);
            $this->assertSame(409, $refused['headers']['status-code'], 'canceled to ' . $status);
            $this->assertSame('distribution_submission_transition_invalid', $refused['body']['type'], 'canceled to ' . $status);
        }

        $after = $this->googleUploads($prepared['identifier']);
        $this->assertSame($before['sessions'] + 1, $after['sessions']);
        $this->assertSame('canceled', $this->getSubmission($appId, $submissionId)['body']['status']);
    }

    public function testDeleteInUse(): void
    {
        $prepared = $this->prepareGoogleRelease(self::IN_USE);
        $appId = $prepared['appId'];
        $before = $this->googleUploads($prepared['identifier']);
        $this->stallGoogleUpload($prepared['identifier'], self::STALL_SECONDS);

        $created = $this->createSubmission($appId, [
            'releaseId' => $prepared['releaseId'],
            'targetId' => $prepared['targetId'],
            'artifactId' => $prepared['artifactId'],
            'track' => 'internal',
        ]);
        $this->assertSame(201, $created['headers']['status-code']);
        $submissionId = $created['body']['$id'];

        $this->assertEventually(function () use ($prepared, $before): void {
            $this->assertSame($before['completed'] + 1, $this->googleUploads($prepared['identifier'])['completed']);
        }, 180_000, 250);

        /**
         * Test for FAILURE
         */
        $target = $this->client->call(Client::METHOD_DELETE, '/distribution/apps/' . $appId . '/targets/' . $prepared['targetId'], $this->headers());
        $this->assertSame(409, $target['headers']['status-code']);
        $this->assertSame('distribution_target_in_use', $target['body']['type']);

        $release = $this->client->call(Client::METHOD_DELETE, '/distribution/apps/' . $appId . '/releases/' . $prepared['releaseId'], $this->headers());
        $this->assertSame(409, $release['headers']['status-code']);
        $this->assertSame('distribution_release_in_use', $release['body']['type']);

        $artifact = $this->client->call(Client::METHOD_DELETE, '/distribution/apps/' . $appId . '/artifacts/' . $prepared['artifactId'], $this->headers());
        $this->assertSame(409, $artifact['headers']['status-code']);
        $this->assertSame('distribution_artifact_in_use', $artifact['body']['type']);

        $active = $this->getSubmission($appId, $submissionId);
        $this->assertSame(200, $active['headers']['status-code']);
        $this->assertContains($active['body']['status'], ['waiting', 'validating', 'uploading', 'processing']);

        /**
         * Test for SUCCESS
         */
        $this->awaitSubmission($appId, $submissionId, 'published');

        $deleted = $this->client->call(Client::METHOD_DELETE, '/distribution/apps/' . $appId . '/releases/' . $prepared['releaseId'], $this->headers());
        $this->assertSame(204, $deleted['headers']['status-code']);
    }
}
