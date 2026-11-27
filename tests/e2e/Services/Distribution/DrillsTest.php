<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Distribution;

use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\System\System;

final class DrillsTest extends Scope
{
    use DistributionBase;
    use MockStores;
    use ProjectCustom;
    use SideServer;

    private const int VARIANT = 6;
    private const int STALL_SECONDS = 25;
    private const int PUBLISHED_TIMEOUT_MS = 360_000;
    private const int START_TIMEOUT_MS = 60_000;
    private const string SOCKET = '/var/run/docker.sock';
    private const string WORKER = 'appwrite-worker-distribution-submissions';
    private const string COMBINED_WORKER = 'appwrite-worker';

    /**
     * PUBLISHED_TIMEOUT_MS must cover the takeover: the first reconcile tick after the 90 s upload claim (Submissions::CLAIM) expires can come up to 2 minutes later, so the worst case publishes about 240 s after the upload starts.
     */
    public function testUploadNotRepeatedAfterWorkerKill(): void
    {
        if (System::getEnv('_APP_E2E_DRILLS', '') !== 'enabled' || !\file_exists(self::SOCKET)) {
            $this->markTestSkipped('The worker kill drill needs _APP_E2E_DRILLS=enabled and the Docker socket at ' . self::SOCKET . '.');
        }

        $prepared = $this->prepareGoogleRelease(self::VARIANT);
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

        $worker = $this->docker('GET', '/containers/' . self::WORKER . '/json') === 200 ? self::WORKER : self::COMBINED_WORKER;

        $this->assertSame(204, $this->docker('POST', '/containers/' . $worker . '/kill'), 'Killing ' . $worker);
        $this->assertEventually(function () use ($worker): void {
            $this->assertContains($this->docker('POST', '/containers/' . $worker . '/start'), [204, 304], 'Starting ' . $worker);
        }, self::START_TIMEOUT_MS, 1_000);

        /**
         * Test for SUCCESS
         */
        $published = $this->awaitSubmission($appId, $submissionId, 'published', self::PUBLISHED_TIMEOUT_MS);
        $this->assertSame('settled', $published['step']);

        $after = $this->googleUploads($prepared['identifier']);
        $this->assertSame($before['sessions'] + 1, $after['sessions'], 'The upload opened a second session after the worker was killed.');
        $this->assertSame($before['completed'] + 1, $after['completed'], 'The bundle was uploaded twice.');
        $this->assertSame($before['bytes'] + $prepared['signedSize'], $after['bytes'], 'Bytes were sent again after the worker was killed.');
    }

    private function docker(string $method, string $path): int
    {
        $curl = \curl_init('http://localhost' . $path);
        \curl_setopt_array($curl, [
            CURLOPT_UNIX_SOCKET_PATH => self::SOCKET,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
        ]);

        $body = \curl_exec($curl);
        $this->assertNotFalse($body, 'Docker ' . $method . ' ' . $path . ': ' . \curl_error($curl));

        return \curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    }
}
