<?php

namespace Tests\E2E\Services\Distribution;

use Appwrite\ID;
use Utopia\Database\Query;

trait SubmissionsBase
{
    public function testCreateSubmissionRefusals(): void
    {
        $prepared = $this->prepareGoogleRelease(self::REFUSED);
        $appId = $prepared['appId'];
        $valid = [
            'releaseId' => $prepared['releaseId'],
            'targetId' => $prepared['targetId'],
            'artifactId' => $prepared['artifactId'],
            'track' => 'internal',
        ];

        $broken = \sys_get_temp_dir() . '/broken-' . \uniqid() . '.aab';
        \file_put_contents($broken, \random_bytes(1024));

        try {
            $upload = $this->uploadBundle($appId, $broken);
        } finally {
            \unlink($broken);
        }

        $this->assertSame(202, $upload['headers']['status-code']);
        $invalid = $this->awaitBundle($appId, $upload['body']['$id']);
        $this->assertSame('invalid', $invalid['status']);

        /**
         * Test for FAILURE
         */
        $release = $this->createSubmission($appId, [...$valid, 'releaseId' => ID::unique()]);
        $this->assertSame(404, $release['headers']['status-code']);
        $this->assertSame('distribution_release_not_found', $release['body']['type']);

        $target = $this->createSubmission($appId, [...$valid, 'targetId' => ID::unique()]);
        $this->assertSame(404, $target['headers']['status-code']);
        $this->assertSame('distribution_target_not_found', $target['body']['type']);

        $artifact = $this->createSubmission($appId, [...$valid, 'artifactId' => ID::unique()]);
        $this->assertSame(404, $artifact['headers']['status-code']);
        $this->assertSame('distribution_artifact_not_found', $artifact['body']['type']);

        $notReady = $this->createSubmission($appId, [...$valid, 'artifactId' => $invalid['$id']]);
        $this->assertSame(409, $notReady['headers']['status-code']);
        $this->assertSame('distribution_artifact_not_ready', $notReady['body']['type']);

        $track = $this->createSubmission($appId, [...$valid, 'track' => 'testflight']);
        $this->assertSame(400, $track['headers']['status-code']);
        $this->assertSame('distribution_track_invalid', $track['body']['type']);

        $incomplete = $this->createSubmission($appId, ['releaseId' => $prepared['releaseId']]);
        $this->assertSame(400, $incomplete['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $incomplete['body']['type']);

        $app = $this->createSubmission(ID::unique(), $valid);
        $this->assertSame(404, $app['headers']['status-code']);
        $this->assertSame('distribution_app_not_found', $app['body']['type']);

        foreach ([0, 1.5, 'x'] as $rollout) {
            $refused = $this->createSubmission($appId, [...$valid, 'rollout' => $rollout]);
            $this->assertSame(400, $refused['headers']['status-code'], 'rollout ' . \json_encode($rollout));
            $this->assertSame('general_argument_invalid', $refused['body']['type'], 'rollout ' . \json_encode($rollout));
        }

        $listed = $this->listSubmissions($appId);
        $this->assertSame(200, $listed['headers']['status-code']);
        $this->assertSame(0, $listed['body']['total']);

        $draft = $this->getRelease($appId, $prepared['releaseId']);
        $this->assertSame(200, $draft['headers']['status-code']);
        $this->assertSame('draft', $draft['body']['status']);
    }

    public function testListSubmissions(): void
    {
        $app = $this->createApp();
        $this->assertSame(201, $app['headers']['status-code']);
        $appId = $app['body']['$id'];

        /**
         * Test for SUCCESS
         */
        $empty = $this->listSubmissions($appId);
        $this->assertSame(200, $empty['headers']['status-code']);
        $this->assertSame(0, $empty['body']['total']);
        $this->assertSame([], $empty['body']['submissions']);

        $filtered = $this->listSubmissions($appId, [
            Query::equal('track', ['internal'])->toString(),
            Query::equal('status', ['published'])->toString(),
        ]);
        $this->assertSame(200, $filtered['headers']['status-code']);
        $this->assertSame(0, $filtered['body']['total']);

        /**
         * Test for FAILURE
         */
        $unknown = $this->listSubmissions($appId, [Query::equal('external', ['x'])->toString()]);
        $this->assertSame(400, $unknown['headers']['status-code']);

        $missing = $this->listSubmissions(ID::unique());
        $this->assertSame(404, $missing['headers']['status-code']);
        $this->assertSame('distribution_app_not_found', $missing['body']['type']);
    }

    public function testGetSubmission(): void
    {
        $app = $this->createApp();
        $this->assertSame(201, $app['headers']['status-code']);

        /**
         * Test for FAILURE
         */
        $missing = $this->getSubmission($app['body']['$id'], ID::unique());
        $this->assertSame(404, $missing['headers']['status-code']);
        $this->assertSame('distribution_submission_not_found', $missing['body']['type']);

        $noApp = $this->getSubmission(ID::unique(), ID::unique());
        $this->assertSame(404, $noApp['headers']['status-code']);
        $this->assertSame('distribution_app_not_found', $noApp['body']['type']);
    }

    public function testUpdateSubmission(): void
    {
        $app = $this->createApp();
        $this->assertSame(201, $app['headers']['status-code']);

        /**
         * Test for FAILURE
         */
        $missing = $this->updateSubmission($app['body']['$id'], ID::unique(), ['automatic' => false]);
        $this->assertSame(404, $missing['headers']['status-code']);
        $this->assertSame('distribution_submission_not_found', $missing['body']['type']);

        $invalid = $this->updateSubmission($app['body']['$id'], ID::unique(), ['automatic' => 'sometimes']);
        $this->assertSame(400, $invalid['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $invalid['body']['type']);

        foreach ([1.5, -0.5, 'x'] as $rollout) {
            $refused = $this->updateSubmission($app['body']['$id'], ID::unique(), ['rollout' => $rollout]);
            $this->assertSame(400, $refused['headers']['status-code'], 'rollout ' . \json_encode($rollout));
            $this->assertSame('general_argument_invalid', $refused['body']['type'], 'rollout ' . \json_encode($rollout));
        }
    }

    public function testUpdateSubmissionStatus(): void
    {
        $app = $this->createApp();
        $this->assertSame(201, $app['headers']['status-code']);

        /**
         * Test for FAILURE
         */
        $missing = $this->updateSubmissionStatus($app['body']['$id'], ID::unique(), 'canceled');
        $this->assertSame(404, $missing['headers']['status-code']);
        $this->assertSame('distribution_submission_not_found', $missing['body']['type']);

        foreach (['rejected', 'waiting', 'failed', ''] as $status) {
            $invalid = $this->updateSubmissionStatus($app['body']['$id'], ID::unique(), $status);
            $this->assertSame(400, $invalid['headers']['status-code'], $status);
            $this->assertSame('general_argument_invalid', $invalid['body']['type'], $status);
        }
    }
}
