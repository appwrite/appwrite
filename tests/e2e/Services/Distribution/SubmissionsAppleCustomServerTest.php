<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Distribution;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;

final class SubmissionsAppleCustomServerTest extends Scope
{
    use DistributionBase;
    use MockStores;
    use ProjectCustom;
    use SideServer;

    private const string BUILD = '1';

    private const string VERSION = '1.0.0';

    private const float FRACTION = 0.5;

    private const array EXEMPT = ['usesNonExemptEncryption' => 'false'];

    private const array HIDDEN = ['checkpoint', 'claim', 'control', 'committed', 'track', 'bundleId', 'version', 'build', 'buildUploadFileId'];

    public function testCreateSubmissionTestFlight(): void
    {
        $prepared = $this->prepareAppleRelease(['configuration' => self::EXEMPT]);
        $appId = $prepared['appId'];

        $created = $this->createSubmission($appId, [
            'releaseId' => $prepared['releaseId'],
            'targetId' => $prepared['targetId'],
            'artifactId' => $prepared['artifactId'],
            'track' => 'testflight',
        ]);

        /**
         * Test for SUCCESS
         */
        $this->assertSame(201, $created['headers']['status-code']);
        $this->assertSame('ios', $created['body']['platform']);
        $this->assertSame('apple', $created['body']['provider']);
        $this->assertSame('testflight', $created['body']['track']);
        $this->assertSame($prepared['targetId'] . ':testflight:' . self::BUILD, $created['body']['key']);

        $approved = $this->awaitSubmission($appId, $created['body']['$id'], 'approved');
        $this->assertSame('READY_FOR_BETA_TESTING', $approved['storeStatus']);
        $this->assertSame('', $approved['errorCode']);
        $this->assertNotEmpty($approved['submittedAt']);
        $this->assertNotEmpty($approved['reviewedAt']);
        $this->assertSame($prepared['storeAppId'], $approved['external']['appId']);
        $this->assertNotEmpty($approved['external']['buildId']);
        $this->assertNotEmpty($approved['external']['buildUploadId']);

        foreach (self::HIDDEN as $key) {
            $this->assertArrayNotHasKey($key, $approved['external'], "The submission response exposes external.{$key}.");
        }

        $target = $this->getTarget($appId, $prepared['targetId']);
        $this->assertSame(200, $target['headers']['status-code']);
        $this->assertSame($prepared['storeAppId'], $target['body']['storeAppId']);
    }

    public function testCreateSubmissionTestFlightMissingCompliance(): void
    {
        $prepared = $this->prepareAppleRelease();
        $appId = $prepared['appId'];

        $created = $this->createSubmission($appId, [
            'releaseId' => $prepared['releaseId'],
            'targetId' => $prepared['targetId'],
            'artifactId' => $prepared['artifactId'],
        ]);
        $this->assertSame(201, $created['headers']['status-code']);
        $this->assertSame('testflight', $created['body']['track']);

        /**
         * Test for SUCCESS
         */
        $pending = $this->awaitSubmission($appId, $created['body']['$id'], 'pending');
        $this->assertSame('MISSING_EXPORT_COMPLIANCE', $pending['storeStatus']);
        $this->assertSame('MISSING_EXPORT_COMPLIANCE', $pending['errorCode']);
        $this->assertNotEmpty($pending['errorMessage']);
        $this->assertSame('released', $pending['step']);
        $this->assertEmpty($pending['publishedAt']);
    }

    public function testCreateSubmissionTestFlightExternal(): void
    {
        $prepared = $this->prepareAppleRelease(['configuration' => self::EXEMPT]);
        $appId = $prepared['appId'];
        $params = [
            'releaseId' => $prepared['releaseId'],
            'targetId' => $prepared['targetId'],
            'artifactId' => $prepared['artifactId'],
        ];

        /**
         * Test for FAILURE
         */
        $ungrouped = $this->createSubmission($appId, [...$params, 'track' => 'testflight-external']);
        $this->assertSame(400, $ungrouped['headers']['status-code']);
        $this->assertSame('distribution_track_invalid', $ungrouped['body']['type']);

        /**
         * Test for SUCCESS
         */
        $groupId = $this->seedAppleGroup($prepared['storeAppId']);
        $updated = $this->updateTarget($appId, $prepared['targetId'], [
            'track' => 'testflight-external',
            'configuration' => [...self::EXEMPT, 'betaGroups' => $groupId],
        ]);
        $this->assertSame(200, $updated['headers']['status-code']);
        $this->assertSame('testflight-external', $updated['body']['track']);
        $this->seedAppleBetaReview($prepared['storeAppId'], self::BUILD, 'BETA_APPROVED');

        $created = $this->createSubmission($appId, $params);
        $this->assertSame(201, $created['headers']['status-code']);
        $this->assertSame('testflight-external', $created['body']['track']);

        $approved = $this->awaitSubmission($appId, $created['body']['$id'], 'approved');
        $this->assertSame('BETA_APPROVED', $approved['storeStatus']);
        $this->assertSame('', $approved['errorCode']);
        $this->assertNotEmpty($approved['external']['betaReviewSubmissionId']);
        $this->assertNotEmpty($approved['external']['buildId']);
    }

    public function testCreateSubmissionProductionManualRelease(): void
    {
        $prepared = $this->prepareAppleRelease(['track' => 'production', 'configuration' => self::EXEMPT]);
        $appId = $prepared['appId'];
        $this->seedAppleReview($prepared['storeAppId'], self::VERSION, 'PENDING_DEVELOPER_RELEASE');

        $created = $this->createSubmission($appId, [
            'releaseId' => $prepared['releaseId'],
            'targetId' => $prepared['targetId'],
            'artifactId' => $prepared['artifactId'],
            'automatic' => false,
        ]);
        $this->assertSame(201, $created['headers']['status-code']);
        $this->assertSame('production', $created['body']['track']);
        $this->assertFalse($created['body']['automatic']);
        $submissionId = $created['body']['$id'];

        $approved = $this->awaitSubmission($appId, $submissionId, 'approved');
        $this->assertSame('PENDING_DEVELOPER_RELEASE', $approved['storeStatus']);
        $this->assertNotEmpty($approved['external']['versionId']);
        $this->assertNotEmpty($approved['external']['reviewSubmissionId']);
        $this->assertEmpty($approved['publishedAt']);

        /**
         * Test for SUCCESS
         */
        $publish = $this->updateSubmissionStatus($appId, $submissionId, 'published');
        $this->assertSame(200, $publish['headers']['status-code']);
        $this->assertSame($submissionId, $publish['body']['$id']);

        $published = $this->awaitSubmission($appId, $submissionId, 'published', 120_000);
        $this->assertSame('READY_FOR_DISTRIBUTION', $published['storeStatus']);
        $this->assertSame('settled', $published['step']);
        $this->assertSame('', $published['errorCode']);
        $this->assertNotEmpty($published['publishedAt']);
        $this->assertEqualsWithDelta(1.0, $published['rollout'], PHP_FLOAT_EPSILON);

        $release = $this->getRelease($appId, $prepared['releaseId']);
        $this->assertSame(200, $release['headers']['status-code']);
        $this->assertSame('published', $release['body']['status']);
    }

    public function testCreateSubmissionProductionMissingCompliance(): void
    {
        $prepared = $this->prepareAppleRelease(['track' => 'production']);
        $appId = $prepared['appId'];

        $created = $this->createSubmission($appId, [
            'releaseId' => $prepared['releaseId'],
            'targetId' => $prepared['targetId'],
            'artifactId' => $prepared['artifactId'],
        ]);
        $this->assertSame(201, $created['headers']['status-code']);

        $submissionId = $created['body']['$id'];

        /**
         * Test for SUCCESS
         */
        $pending = $this->awaitSubmission($appId, $submissionId, 'pending');
        $this->assertSame('processed', $pending['step']);
        $this->assertSame('MISSING_EXPORT_COMPLIANCE', $pending['errorCode']);
        $this->assertStringContainsString('usesNonExemptEncryption', (string) $pending['errorMessage']);
        $this->assertNotEmpty($pending['external']['buildId']);
        $this->assertArrayNotHasKey('reviewSubmissionId', $pending['external']);
        $this->assertEmpty($pending['submittedAt']);
        $this->assertEmpty($pending['publishedAt']);

        $release = $this->getRelease($appId, $prepared['releaseId']);
        $this->assertSame(200, $release['headers']['status-code']);
        $this->assertSame('active', $release['body']['status'], 'A submission waiting on export compliance needs no attention.');
    }

    public function testCreateSubmissionAppleRefusals(): void
    {
        $prepared = $this->prepareAppleRelease(['configuration' => self::EXEMPT]);
        $appId = $prepared['appId'];
        $params = [
            'releaseId' => $prepared['releaseId'],
            'targetId' => $prepared['targetId'],
            'artifactId' => $prepared['artifactId'],
        ];

        /**
         * Test for FAILURE
         */
        $track = $this->createSubmission($appId, [...$params, 'track' => 'internal']);
        $this->assertSame(400, $track['headers']['status-code']);
        $this->assertSame('distribution_track_invalid', $track['body']['type']);

        $staged = $this->createSubmission($appId, [...$params, 'rollout' => self::FRACTION]);
        $this->assertSame(400, $staged['headers']['status-code'], 'Apple phased release percentages are fixed, so a staged rollout is refused.');
        $this->assertSame('general_argument_invalid', $staged['body']['type']);

        $none = $this->listSubmissions($appId);
        $this->assertSame(200, $none['headers']['status-code']);
        $this->assertSame(0, $none['body']['total']);

        $created = $this->createSubmission($appId, [...$params, 'rollout' => 1]);
        $this->assertSame(201, $created['headers']['status-code']);
        $this->assertTrue($created['body']['automatic']);
        $this->assertEqualsWithDelta(1.0, (float) $created['body']['rollout'], PHP_FLOAT_EPSILON);
        $submissionId = $created['body']['$id'];

        $restaged = $this->updateSubmission($appId, $submissionId, ['rollout' => self::FRACTION]);
        $this->assertSame(400, $restaged['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $restaged['body']['type']);
        $this->assertEqualsWithDelta(1.0, (float) $this->getSubmission($appId, $submissionId)['body']['rollout'], PHP_FLOAT_EPSILON);

        $approved = $this->awaitSubmission($appId, $submissionId, 'approved');
        $this->assertSame('released', $approved['step']);

        foreach (['canceled', 'halted', 'published'] as $status) {
            $refused = $this->updateSubmissionStatus($appId, $submissionId, $status);
            $this->assertSame(409, $refused['headers']['status-code'], 'released TestFlight to ' . $status);
            $this->assertSame('distribution_submission_transition_invalid', $refused['body']['type'], 'released TestFlight to ' . $status);
        }

        $unchanged = $this->getSubmission($appId, $submissionId);
        $this->assertSame(200, $unchanged['headers']['status-code']);
        $this->assertSame('approved', $unchanged['body']['status']);
        $this->assertSame('READY_FOR_BETA_TESTING', $unchanged['body']['storeStatus']);
        $this->assertSame('', $unchanged['body']['errorCode']);
    }

    /**
     * @return array<string, mixed>
     */
    private function getTarget(string $appId, string $targetId): array
    {
        return $this->client->call(Client::METHOD_GET, '/distribution/apps/' . $appId . '/targets/' . $targetId, $this->headers());
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function updateTarget(string $appId, string $targetId, array $params): array
    {
        return $this->client->call(Client::METHOD_PATCH, '/distribution/apps/' . $appId . '/targets/' . $targetId, $this->headers(), $params);
    }
}
