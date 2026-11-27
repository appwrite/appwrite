<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Distribution;

use Appwrite\ID;
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
    private const int STAGED = 31;
    private const int ROLLOUT = 32;
    private const int APPROVED = 33;
    private const int PROMOTED = 34;
    private const int FOREIGN = 35;
    private const int STALL_SECONDS = 25;
    private const int CONTROL = 120_000;
    private const int QUIET = 20_000;
    private const float FRACTION = 0.5;
    private const float STAGE = 0.2;
    private const float WIDER = 0.4;
    private const string LIFECYCLE_APPROVED = 'RELEASE_LIFECYCLE_STATE_APPROVED_NOT_PUBLISHED';
    private const string LIFECYCLE_PUBLISHED = 'RELEASE_LIFECYCLE_STATE_PUBLISHED';
    private const string PROMOTED_TITLE = 'Promoted';

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

        $raised = $this->updateSubmission($appId, $submissionId, ['rollout' => 1]);
        $this->assertSame(409, $raised['headers']['status-code'], 'A rollout change never resumes a halted rollout.');
        $this->assertSame('distribution_submission_transition_invalid', $raised['body']['type']);

        $stillHalted = $this->getSubmission($appId, $submissionId)['body'];
        $this->assertSame('halted', $stillHalted['status']);
        $this->assertSame(self::FRACTION, (float) $stillHalted['rollout']);

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

        foreach (['checkpoint', 'claim', 'control'] as $key) {
            $this->assertArrayNotHasKey($key, $uploading['body']['external'], "An uploading submission exposes external.{$key}.");
        }

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

    public function testCreateSubmissionStagedRollout(): void
    {
        $prepared = $this->prepareGoogleRelease(self::STAGED);
        $appId = $prepared['appId'];
        $before = $this->googleUploads($prepared['identifier']);
        $params = [
            'releaseId' => $prepared['releaseId'],
            'targetId' => $prepared['targetId'],
            'artifactId' => $prepared['artifactId'],
            'track' => 'production',
        ];

        $created = $this->createSubmission($appId, [...$params, 'rollout' => self::STAGE]);

        /**
         * Test for SUCCESS
         */
        $this->assertSame(201, $created['headers']['status-code'], \json_encode($created['body']));
        $submissionId = $created['body']['$id'];
        $this->assertSame('production', $created['body']['track']);
        $this->assertSame(self::STAGE, (float) $created['body']['rollout']);

        $published = $this->awaitSubmission($appId, $submissionId, 'published');
        $this->assertSame(self::STAGE, (float) $published['rollout'], 'The store rolls the release out to the requested fraction.');
        $this->assertSame('released', $published['step'], 'A staged rollout is not settled until it reaches every user.');
        $this->assertSame('', $published['errorCode']);
        $this->assertSame($before['completed'] + 1, $this->googleUploads($prepared['identifier'])['completed']);

        $repeated = $this->createSubmission($appId, [...$params, 'rollout' => self::FRACTION]);
        $this->assertSame(200, $repeated['headers']['status-code'], \json_encode($repeated['body']));
        $this->assertSame($submissionId, $repeated['body']['$id']);
        $this->assertSame(self::STAGE, (float) $repeated['body']['rollout'], 'Repeating a key returns the existing submission at its own rollout.');

        /**
         * Test for FAILURE
         */
        $reused = $this->createSubmission($appId, [...$params, 'rollout' => self::FRACTION, 'key' => 'e2e-' . \uniqid()]);
        $this->assertSame(400, $reused['headers']['status-code']);
        $this->assertSame('distribution_version_invalid', $reused['body']['type']);

        $this->assertSame(self::STAGE, (float) $this->getSubmission($appId, $submissionId)['body']['rollout']);
    }

    public function testUpdateSubmissionRollout(): void
    {
        $prepared = $this->prepareGoogleRelease(self::ROLLOUT);
        $appId = $prepared['appId'];
        $before = $this->googleUploads($prepared['identifier']);
        $this->stallGoogleUpload($prepared['identifier'], self::STALL_SECONDS);

        $created = $this->createSubmission($appId, [
            'releaseId' => $prepared['releaseId'],
            'targetId' => $prepared['targetId'],
            'artifactId' => $prepared['artifactId'],
            'track' => 'production',
        ]);
        $this->assertSame(201, $created['headers']['status-code'], \json_encode($created['body']));
        $this->assertEqualsWithDelta(1.0, (float) $created['body']['rollout'], PHP_FLOAT_EPSILON);
        $submissionId = $created['body']['$id'];

        $this->assertEventually(function () use ($prepared, $before): void {
            $this->assertSame($before['completed'] + 1, $this->googleUploads($prepared['identifier'])['completed']);
        }, 180_000, 250);

        /**
         * Test for SUCCESS
         */
        $staged = $this->updateSubmission($appId, $submissionId, ['rollout' => self::WIDER]);
        $this->assertSame(200, $staged['headers']['status-code'], \json_encode($staged['body']));
        $this->assertSame($submissionId, $staged['body']['$id']);
        $this->assertSame('uploading', $staged['body']['status'], 'The rollout changes while the upload is still in progress.');
        $this->assertSame(self::WIDER, (float) $staged['body']['rollout'], 'Before release a rollout change is written at once.');
        $this->assertSame(self::WIDER, (float) $this->getSubmission($appId, $submissionId)['body']['rollout']);

        /**
         * Test for FAILURE
         */
        $empty = $this->updateSubmission($appId, $submissionId, []);
        $this->assertSame(400, $empty['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $empty['body']['type']);

        $zero = $this->updateSubmission($appId, $submissionId, ['rollout' => 0]);
        $this->assertSame(400, $zero['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $zero['body']['type']);

        $this->assertSame(self::WIDER, (float) $this->getSubmission($appId, $submissionId)['body']['rollout'], 'A refused update writes nothing.');

        /**
         * Test for SUCCESS
         */
        $published = $this->awaitSubmission($appId, $submissionId, 'published');
        $this->assertSame(self::WIDER, (float) $published['rollout'], 'The release goes out at the rollout set before it was released.');
        $this->assertSame('released', $published['step']);
        $this->assertTrue($published['automatic']);

        /**
         * Test for FAILURE
         */
        $mixed = $this->updateSubmission($appId, $submissionId, ['automatic' => false, 'rollout' => self::FRACTION]);
        $this->assertSame(409, $mixed['headers']['status-code']);
        $this->assertSame('distribution_submission_transition_invalid', $mixed['body']['type']);

        $automatic = $this->updateSubmission($appId, $submissionId, ['automatic' => false]);
        $this->assertSame(409, $automatic['headers']['status-code']);
        $this->assertSame('distribution_submission_transition_invalid', $automatic['body']['type']);

        $this->assertStays(
            fn (): array => $this->rolloutState($appId, $submissionId),
            ['status' => 'published', 'step' => 'released', 'rollout' => self::WIDER, 'automatic' => true],
            'An update that also changes automatic after release applies nothing',
            self::QUIET,
        );

        /**
         * Test for SUCCESS
         */
        $raised = $this->updateSubmission($appId, $submissionId, ['rollout' => self::FRACTION]);
        $this->assertSame(200, $raised['headers']['status-code'], \json_encode($raised['body']));
        $this->assertSame($submissionId, $raised['body']['$id']);

        $this->assertEventually(function () use ($appId, $submissionId): void {
            $submission = $this->getSubmission($appId, $submissionId)['body'];
            $this->assertSame('published', $submission['status']);
            $this->assertSame(self::FRACTION, (float) $submission['rollout']);
            $this->assertSame('released', $submission['step']);
        }, self::CONTROL, 2000);

        $same = $this->updateSubmission($appId, $submissionId, ['rollout' => self::FRACTION]);
        $this->assertSame(200, $same['headers']['status-code'], \json_encode($same['body']));
        $this->assertSame(self::FRACTION, (float) $same['body']['rollout'], 'An unchanged rollout is a no-op.');
        $this->assertSame('released', $same['body']['step']);

        /**
         * Test for FAILURE
         */
        $lowered = $this->updateSubmission($appId, $submissionId, ['rollout' => self::WIDER]);
        $this->assertSame(409, $lowered['headers']['status-code'], 'Google Play never lowers a staged rollout.');
        $this->assertSame('distribution_submission_transition_invalid', $lowered['body']['type']);
        $this->assertSame(self::FRACTION, (float) $this->getSubmission($appId, $submissionId)['body']['rollout']);

        /**
         * Test for SUCCESS
         */
        $full = $this->updateSubmission($appId, $submissionId, ['rollout' => 1]);
        $this->assertSame(200, $full['headers']['status-code'], \json_encode($full['body']));

        $this->assertEventually(function () use ($appId, $submissionId): void {
            $submission = $this->getSubmission($appId, $submissionId)['body'];
            $this->assertSame('published', $submission['status']);
            $this->assertEqualsWithDelta(1.0, (float) $submission['rollout'], PHP_FLOAT_EPSILON);
            $this->assertSame('settled', $submission['step'], 'A full rollout settles the submission.');
        }, self::CONTROL, 2000);

        $this->assertSame($before['completed'] + 1, $this->googleUploads($prepared['identifier'])['completed'], 'A rollout change uploads nothing.');

        /**
         * Test for FAILURE
         */
        $rolledOut = $this->updateSubmission($appId, $submissionId, ['rollout' => self::FRACTION]);
        $this->assertSame(409, $rolledOut['headers']['status-code']);
        $this->assertSame('distribution_submission_transition_invalid', $rolledOut['body']['type']);

        $unchanged = $this->getSubmission($appId, $submissionId);
        $this->assertSame('settled', $unchanged['body']['step']);
        $this->assertEqualsWithDelta(1.0, (float) $unchanged['body']['rollout'], PHP_FLOAT_EPSILON);
    }

    public function testApprovedStagedRolloutKeepsFraction(): void
    {
        $prepared = $this->prepareGoogleRelease(self::APPROVED);
        $appId = $prepared['appId'];
        $this->seedGoogleReview($prepared['identifier'], 'production', self::LIFECYCLE_APPROVED);

        $created = $this->createSubmission($appId, [
            'releaseId' => $prepared['releaseId'],
            'targetId' => $prepared['targetId'],
            'artifactId' => $prepared['artifactId'],
            'track' => 'production',
            'rollout' => self::STAGE,
        ]);
        $this->assertSame(201, $created['headers']['status-code'], \json_encode($created['body']));
        $submissionId = $created['body']['$id'];

        /**
         * Test for SUCCESS
         */
        $approved = $this->awaitSubmission($appId, $submissionId, 'approved');
        $this->assertSame('released', $approved['step']);
        $this->assertSame(self::LIFECYCLE_APPROVED, $approved['storeStatus']);
        $this->assertSame(self::STAGE, (float) $approved['rollout'], 'An approved release keeps the requested fraction.');

        /**
         * Test for FAILURE
         */
        $early = $this->updateSubmission($appId, $submissionId, ['rollout' => self::FRACTION]);
        $this->assertSame(409, $early['headers']['status-code'], 'An approved release changes its rollout only once it is published.');
        $this->assertSame('distribution_submission_transition_invalid', $early['body']['type']);
        $this->assertSame(self::STAGE, (float) $this->getSubmission($appId, $submissionId)['body']['rollout']);

        /**
         * Test for SUCCESS
         */
        $this->seedGoogleReview($prepared['identifier'], 'production', self::LIFECYCLE_PUBLISHED);
        $publish = $this->updateSubmissionStatus($appId, $submissionId, 'published');
        $this->assertSame(200, $publish['headers']['status-code'], \json_encode($publish['body']));

        $published = $this->awaitSubmission($appId, $submissionId, 'published', self::CONTROL);
        $this->assertSame(self::STAGE, (float) $published['rollout'], 'Publishing an approved staged release keeps its fraction.');
        $this->assertSame('released', $published['step']);
        $this->assertSame('', $published['errorCode']);

        $this->assertStays(
            fn (): array => $this->rolloutState($appId, $submissionId),
            ['status' => 'published', 'step' => 'released', 'rollout' => self::STAGE, 'automatic' => true],
            'A published staged release never rolls out to every user on its own',
            self::QUIET,
        );
    }

    public function testCreateSubmissionPromotesWithoutUpload(): void
    {
        $prepared = $this->prepareGoogleRelease(self::PROMOTED);
        $appId = $prepared['appId'];
        $params = [
            'releaseId' => $prepared['releaseId'],
            'targetId' => $prepared['targetId'],
            'artifactId' => $prepared['artifactId'],
        ];

        $internal = $this->createSubmission($appId, [...$params, 'track' => 'internal']);
        $this->assertSame(201, $internal['headers']['status-code'], \json_encode($internal['body']));
        $this->assertSame('settled', $this->awaitSubmission($appId, $internal['body']['$id'], 'published')['step']);
        $before = $this->googleUploads($prepared['identifier']);

        $listing = $this->client->call(Client::METHOD_POST, '/distribution/apps/' . $appId . '/listings', $this->headers(), [
            'listingId' => ID::unique(),
            'platform' => 'android',
            'locale' => 'en-US',
            'fields' => ['name' => self::PROMOTED_TITLE],
        ]);
        $this->assertSame(201, $listing['headers']['status-code'], \json_encode($listing['body']));

        /**
         * Test for SUCCESS
         */
        $promoted = $this->createSubmission($appId, [...$params, 'track' => 'production']);
        $this->assertSame(201, $promoted['headers']['status-code'], \json_encode($promoted['body']));
        $this->assertSame('production', $promoted['body']['track']);
        $this->assertSame($prepared['targetId'] . ':production:1', $promoted['body']['key']);
        $submissionId = $promoted['body']['$id'];

        $published = $this->awaitSubmission($appId, $submissionId, 'published');
        $this->assertSame('settled', $published['step']);
        $this->assertSame('', $published['errorCode']);
        $this->assertSame('1', (string) $published['external']['versionCode']);
        $this->assertSame($before, $this->googleUploads($prepared['identifier']), 'A promotion uploads nothing.');
        $this->assertSame(self::PROMOTED_TITLE, $this->googleListings($prepared['identifier'])['en-US']['title'] ?? null, 'The listing is committed with the promoted release.');

        $listed = $this->listSubmissions($appId, [Query::equal('artifactId', [$prepared['artifactId']])->toString()]);
        $this->assertSame(200, $listed['headers']['status-code']);
        $this->assertEqualsCanonicalizing(['internal', 'production'], \array_column($listed['body']['submissions'], 'track'));

        /**
         * Test for FAILURE
         */
        $again = $this->createSubmission($appId, [...$params, 'track' => 'production', 'key' => 'e2e-' . \uniqid()]);
        $this->assertSame(400, $again['headers']['status-code']);
        $this->assertSame('distribution_version_invalid', $again['body']['type']);

        $rebuilt = $this->uploadBundle($appId, __DIR__ . '/../../../resources/distribution/' . $prepared['identifier'] . '.aab');
        $this->assertSame(202, $rebuilt['headers']['status-code'], \json_encode($rebuilt['body']));
        $ready = $this->awaitBundle($appId, $rebuilt['body']['$id']);
        $this->assertSame('ready', $ready['status'], $ready['errorCode'] . ' ' . $ready['errorMessage']);
        $this->assertSame(1, $ready['versionCode']);
        $this->assertNotSame($prepared['artifactId'], $ready['$id']);

        $foreign = $this->createSubmission($appId, [...$params, 'artifactId' => $ready['$id'], 'track' => 'beta']);
        $this->assertSame(201, $foreign['headers']['status-code'], \json_encode($foreign['body']));

        $failed = $this->awaitSubmission($appId, $foreign['body']['$id'], 'failed');
        $this->assertSame('distribution_check_failed', $failed['errorCode']);
        $this->assertStringContainsString('is not higher than', (string) $failed['errorMessage'], 'Another artifact with a released version code is not a promotion.');
        $this->assertSame($before, $this->googleUploads($prepared['identifier']), 'A refused submission uploads nothing.');
    }

    public function testCreateSubmissionPromotionRefusedForForeignVersion(): void
    {
        $prepared = $this->prepareGoogleRelease(self::FOREIGN);
        $appId = $prepared['appId'];
        $this->seedGoogleApplication($prepared['identifier'], $prepared['email'], [
            ['track' => 'production', 'releases' => []],
            ['track' => 'beta', 'releases' => []],
            ['track' => 'alpha', 'releases' => []],
            ['track' => 'internal', 'releases' => [['versionCodes' => ['1'], 'status' => 'completed']]],
        ]);
        $before = $this->googleUploads($prepared['identifier']);

        $created = $this->createSubmission($appId, [
            'releaseId' => $prepared['releaseId'],
            'targetId' => $prepared['targetId'],
            'artifactId' => $prepared['artifactId'],
            'track' => 'production',
        ]);
        $this->assertSame(201, $created['headers']['status-code'], \json_encode($created['body']));

        /**
         * Test for FAILURE
         */
        $failed = $this->awaitSubmission($appId, $created['body']['$id'], 'failed');
        $this->assertSame('distribution_check_failed', $failed['errorCode']);
        $this->assertStringContainsString('is not higher than', (string) $failed['errorMessage'], 'A version code that reached the store outside Appwrite is not a promotion.');
        $this->assertSame($before, $this->googleUploads($prepared['identifier']), 'A refused submission uploads nothing.');
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

    /**
     * @return array{status: string, step: string, rollout: float, automatic: bool}
     */
    private function rolloutState(string $appId, string $submissionId): array
    {
        $submission = $this->getSubmission($appId, $submissionId);
        $this->assertSame(200, $submission['headers']['status-code']);

        return [
            'status' => $submission['body']['status'],
            'step' => $submission['body']['step'],
            'rollout' => (float) $submission['body']['rollout'],
            'automatic' => $submission['body']['automatic'],
        ];
    }
}
