<?php

namespace Tests\E2E\Services\Distribution;

use Appwrite\ID;
use Appwrite\Tests\Async\Exceptions\Critical;
use Tests\E2E\Client;
use Utopia\Distribution\Signing\Certificate;
use Utopia\System\System;

/**
 * Plays the macOS runner agent against the runner and lease routes.
 */
trait RunnersBase
{
    private const string RUNNER_TEAM = 'ABCDE12345';

    private const string RUNNER_VERSION = '1.0.0';

    private const string RUNNER_IMAGE = 'macos-15-xcode-16';

    private const string RUNNER_SOURCES = "name: fixture\nenvironment:\n  sdk: '>=3.0.0 <4.0.0'\n";

    private const int UPLOAD_CHUNK = 5_242_880;

    private const int PROFILE_DAYS = 200;

    private const int CLAIM_TIMEOUT = 180_000;

    private const int QUIET_WINDOW = 20_000;

    /**
     * Every build this test created through createIosBuild().
     *
     * @var list<string>
     */
    private array $builds = [];

    /**
     * An app with a Gitea repository and an iOS target whose imported signing identity holds a
     * profile for the target, labelled with an Xcode version no other test uses.
     *
     * @return array{appId: string, targetId: string, identifier: string, labels: list<string>, archive: string, password: string, fingerprint: string, profile: string, profileExpiresAt: int}
     */
    protected function prepareIosBuild(): array
    {
        $installationId = $this->createGiteaInstallation()['$id'];
        $repository = $this->createGiteaRepository(['pubspec.yaml' => self::RUNNER_SOURCES]);

        $app = $this->createApp([
            'installationId' => $installationId,
            'providerRepositoryId' => (string) $repository['id'],
            'providerBranch' => self::GITEA_BRANCH,
            'providerDeployments' => false,
        ]);
        $this->assertSame(201, $app['headers']['status-code'], \json_encode($app['body']));

        $identifier = 'io.appwrite.runner' . \uniqid();
        $identity = $this->iosIdentity();
        $profileExpiresAt = \time() + self::PROFILE_DAYS * 86400;
        $profile = $this->iosProfile($identity['certificate'], $identifier, $profileExpiresAt);

        $signingIdentity = $this->client->call(Client::METHOD_POST, '/distribution/signing-identities/ios', $this->headers(), [
            'signingIdentityId' => ID::unique(),
            'name' => 'Certificate ' . \uniqid(),
            'certificate' => \base64_encode($identity['archive']),
            'password' => $identity['password'],
            'profiles' => [\base64_encode($profile)],
        ]);
        $this->assertSame(201, $signingIdentity['headers']['status-code'], \json_encode($signingIdentity['body']));

        $xcode = \bin2hex(\random_bytes(8));
        $target = $this->createTarget($app['body']['$id'], [
            'platform' => 'ios',
            'identifier' => $identifier,
            'signingIdentityId' => $signingIdentity['body']['$id'],
            'configuration' => ['xcode' => $xcode],
        ]);
        $this->assertSame(201, $target['headers']['status-code'], \json_encode($target['body']));

        return [
            'appId' => $app['body']['$id'],
            'targetId' => $target['body']['$id'],
            'identifier' => $identifier,
            'labels' => ['xcode-' . $xcode],
            'archive' => $identity['archive'],
            'password' => $identity['password'],
            'fingerprint' => $identity['certificate']->fingerprint(),
            'profile' => $profile,
            'profileExpiresAt' => $profileExpiresAt,
        ];
    }

    /**
     * Every build carries a version name, which the agent requires until it can keep the project's.
     *
     * @param array{appId: string, targetId: string} $prepared
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    protected function createIosBuild(array $prepared, array $params = []): array
    {
        $created = $this->createBuild($prepared['appId'], [
            'targetId' => $prepared['targetId'],
            'versionName' => '1.0.' . \random_int(1, 999999),
            ...$params,
        ]);
        $this->assertSame(202, $created['headers']['status-code'], \json_encode($created['body']));
        $this->assertSame('ios', $created['body']['platform']);
        $this->builds[] = $created['body']['$id'];

        return $created['body'];
    }

    /**
     * Waits until dispatch has queued the build's lease.
     *
     * @return array<string, mixed>
     */
    protected function awaitDispatch(string $appId, string $buildId): array
    {
        $build = [];

        $this->assertEventually(function () use ($appId, $buildId, &$build): void {
            $fetched = $this->getBuild($appId, $buildId);
            $this->assertSame(200, $fetched['headers']['status-code']);
            $build = $fetched['body'];

            if (\in_array($build['status'], ['ready', 'failed', 'canceled'], true)) {
                throw new Critical('Build ' . $buildId . ' ended ' . $build['status'] . ' before it was leased: ' . $build['errorCode'] . ' ' . $build['errorMessage']);
            }

            $this->assertNotEmpty($build['dispatchedAt'], 'The build has not been dispatched yet.');
            $this->assertSame('build', $build['stage']);
        }, 120_000, 1000);

        return $build;
    }

    protected function cancelBuild(string $appId, string $buildId): void
    {
        $this->client->call(Client::METHOD_PATCH, '/distribution/apps/' . $appId . '/builds/' . $buildId . '/status', $this->headers(), [
            'status' => 'canceled',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getArtifact(string $appId, string $artifactId): array
    {
        return $this->client->call(Client::METHOD_GET, '/distribution/apps/' . $appId . '/artifacts/' . $artifactId, $this->headers());
    }

    /**
     * The log rows of a build whose content holds the marker.
     *
     * @return list<array<string, mixed>>
     */
    protected function markedLogs(string $appId, string $buildId, string $marker): array
    {
        $logs = $this->client->call(Client::METHOD_GET, '/distribution/apps/' . $appId . '/builds/' . $buildId . '/logs', $this->headers());
        $this->assertSame(200, $logs['headers']['status-code'], \json_encode($logs['body']));

        return \array_values(\array_filter(
            $logs['body']['logs'],
            fn (array $log): bool => \str_contains((string) $log['content'], $marker),
        ));
    }

    protected function runnerSecret(): string
    {
        $secret = System::getEnv('_APP_DISTRIBUTION_RUNNER_SECRET', '');
        $this->assertNotSame('', $secret, 'The runner enrolment secret _APP_DISTRIBUTION_RUNNER_SECRET must be set in the test container.');

        return $secret;
    }

    /**
     * Enrols without the project header, as the agent does.
     *
     * @param list<string> $labels
     * @return array<string, mixed>
     */
    protected function enrolRunner(string $authorization, array $labels, int $capacity = 1): array
    {
        $headers = [
            'content-type' => 'application/json',
            'accept' => 'application/json',
        ];
        if ($authorization !== '') {
            $headers['authorization'] = $authorization;
        }

        return $this->client->call(Client::METHOD_POST, '/distribution/runners', $headers, [
            'name' => 'Runner ' . \uniqid(),
            'platform' => 'macos',
            'labels' => $labels,
            'capacity' => $capacity,
            'version' => self::RUNNER_VERSION,
        ]);
    }

    /**
     * @param list<string> $labels
     * @return array{runnerId: string, token: string, labels: list<string>}
     */
    protected function enrol(array $labels, int $capacity = 1): array
    {
        $enrolled = $this->enrolRunner('Bearer ' . $this->runnerSecret(), $labels, $capacity);
        $this->assertSame(201, $enrolled['headers']['status-code'], \json_encode($enrolled['body']));
        $this->assertNotEmpty($enrolled['body']['runnerId']);
        $this->assertNotEmpty($enrolled['body']['token']);

        return [
            'runnerId' => $enrolled['body']['runnerId'],
            'token' => $enrolled['body']['token'],
            'labels' => $labels,
        ];
    }

    /**
     * @param array{runnerId: string, token: string, labels: list<string>} $runner
     * @return array<string, string>
     */
    protected function runnerHeaders(array $runner, ?string $token = null): array
    {
        return [
            'content-type' => 'application/json',
            'accept' => 'application/json',
            'authorization' => 'Bearer ' . ($token ?? $runner['token']),
        ];
    }

    /**
     * @param array{runnerId: string, token: string, labels: list<string>} $runner
     * @return array<string, mixed>
     */
    protected function beat(array $runner, string $status, ?string $token = null): array
    {
        return $this->client->call(Client::METHOD_PATCH, '/distribution/runners/' . $runner['runnerId'], $this->runnerHeaders($runner, $token), [
            'status' => $status,
            'version' => self::RUNNER_VERSION,
            'labels' => $runner['labels'],
        ]);
    }

    /**
     * Sends the `offline` heartbeat the agent sends at exit, so no later dispatch counts the runner.
     *
     * @param array{runnerId: string, token: string, labels: list<string>} $runner
     */
    protected function retire(array $runner): void
    {
        $retired = $this->beat($runner, 'offline');
        $this->assertSame(200, $retired['headers']['status-code'], \json_encode($retired['body']));
    }

    /**
     * @param array{runnerId: string, token: string, labels: list<string>} $runner
     * @return array<string, mixed>
     */
    protected function claim(array $runner, ?string $token = null): array
    {
        return $this->client->call(Client::METHOD_POST, '/distribution/runners/' . $runner['runnerId'] . '/leases', $this->runnerHeaders($runner, $token), [
            'labels' => $runner['labels'],
        ]);
    }

    /**
     * Claims until a lease of one of this test's builds is handed out. A lease of another
     * test's build is queued again, so no test takes a build from another.
     *
     * @param array{runnerId: string, token: string, labels: list<string>} $runner
     * @return array<string, mixed> The lease
     */
    protected function awaitLease(array $runner): array
    {
        return $this->awaitClaim($runner)['body'];
    }

    /**
     * awaitLease() with the whole claim response.
     *
     * @param array{runnerId: string, token: string, labels: list<string>} $runner
     * @return array<string, mixed>
     */
    protected function awaitClaim(array $runner): array
    {
        $claim = [];

        $this->assertEventually(function () use ($runner, &$claim): void {
            $claimed = $this->claim($runner);
            $status = $claimed['headers']['status-code'];

            if (!\in_array($status, [201, 204], true)) {
                throw new Critical('The claim answered ' . $status . ': ' . \json_encode($claimed['body']));
            }

            $this->assertSame(201, $status, 'No lease is queued for the runner yet.');

            if (!\in_array($claimed['body']['buildId'] ?? null, $this->builds, true)) {
                $this->requeue($runner, $claimed['body']);
                $this->fail("The runner claimed a lease of another test's build, which was queued again.");
            }

            $claim = $claimed;
        }, self::CLAIM_TIMEOUT, 1000);

        return $claim;
    }

    /**
     * @param array{runnerId: string} $runner
     * @param array<string, mixed> $lease
     */
    protected function leasePath(array $runner, array $lease, string $suffix = ''): string
    {
        return '/distribution/runners/' . $runner['runnerId'] . '/leases/' . $lease['leaseId'] . $suffix;
    }

    /**
     * The lease token plus one `X-Appwrite-<Key>` header for each `query` entry, as the agent sends them.
     *
     * @param array<string, mixed> $lease
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    protected function leaseHeaders(array $lease, array $overrides = []): array
    {
        $headers = [
            'content-type' => 'application/json',
            'accept' => 'application/json',
            'authorization' => 'Bearer ' . $lease['token'],
        ];

        foreach ($lease['query'] as $key => $value) {
            $headers['x-appwrite-' . \strtolower((string) $key)] = (string) $value;
        }

        return \array_merge($headers, $overrides);
    }

    /**
     * @param array{runnerId: string} $runner
     * @param array<string, mixed> $lease
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    protected function extend(array $runner, array $lease, array $headers = []): array
    {
        return $this->client->call(Client::METHOD_PATCH, $this->leasePath($runner, $lease), $this->leaseHeaders($lease, $headers), []);
    }

    /**
     * @param array{runnerId: string} $runner
     * @param array<string, mixed> $lease
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    protected function source(array $runner, array $lease, array $headers = []): array
    {
        return $this->client->call(Client::METHOD_GET, $this->leasePath($runner, $lease, '/source'), $this->leaseHeaders($lease, $headers));
    }

    /**
     * @param array{runnerId: string} $runner
     * @param array<string, mixed> $lease
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    protected function requeue(array $runner, array $lease, array $headers = []): array
    {
        return $this->client->call(Client::METHOD_PATCH, $this->leasePath($runner, $lease, '/status'), $this->leaseHeaders($lease, $headers), [
            'status' => 'queued',
        ]);
    }

    /**
     * @param array{runnerId: string} $runner
     * @param array<string, mixed> $lease
     * @param list<array<string, mixed>> $events
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    protected function postEvents(array $runner, array $lease, array $events, array $headers = []): array
    {
        return $this->client->call(Client::METHOD_POST, $this->leasePath($runner, $lease, '/events'), $this->leaseHeaders($lease, $headers), [
            'events' => $events,
        ]);
    }

    /**
     * @param array<string, mixed> $lease
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function leaseEvent(array $lease, string $type, array $data = []): array
    {
        return [
            'id' => $lease['leaseId'] . '-' . \hrtime(true),
            'type' => $type,
            'time' => \gmdate('Y-m-d\TH:i:s\Z'),
            'data' => $data,
        ];
    }

    /**
     * The events an agent sends before the build script writes its first line.
     *
     * @param array<string, mixed> $lease
     * @return list<array<string, mixed>>
     */
    protected function opening(array $lease): array
    {
        return [
            $this->leaseEvent($lease, 'start'),
            $this->leaseEvent($lease, 'stage', ['stage' => 'source']),
            $this->leaseEvent($lease, 'stage', ['stage' => 'build']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function exitData(int $exitCode, ?string $reason = null, ?string $error = null): array
    {
        $data = [
            'exitCode' => $exitCode,
            'image' => self::RUNNER_IMAGE,
            'durationSeconds' => $exitCode === -1 ? 0.0 : 42.0,
        ];

        if ($reason !== null) {
            $data['reason'] = $reason;
        }

        if ($error !== null) {
            $data['error'] = ['code' => $error, 'message' => 'The runner reported ' . $error . '.'];
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    protected function failedArtifact(string $artifactId, string $artifactType, string $error): array
    {
        return [
            'artifactId' => $artifactId,
            'artifactType' => $artifactType,
            'status' => 'failed',
            'durationSeconds' => 0.1,
            'error' => ['code' => $error, 'message' => 'The runner reported ' . $error . '.'],
        ];
    }

    /**
     * The `result` content the agent reads from the verified IPA.
     *
     * @param array{identifier: string} $prepared
     * @param array<string, mixed> $build
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected function outcome(array $prepared, array $build, string $ipa, array $overrides = []): array
    {
        return [
            'artifact' => 'app.ipa',
            'type' => 'ipa',
            'digest' => \hash('sha256', $ipa),
            'size' => \strlen($ipa),
            'identifier' => $prepared['identifier'],
            'versionName' => $build['versionName'],
            'versionCode' => (int) $build['buildNumber'],
            'buildNumber' => $build['buildNumber'],
            'teamId' => self::RUNNER_TEAM,
            'signed' => true,
            'entitlements' => ['application-identifier' => self::RUNNER_TEAM . '.' . $prepared['identifier']],
            'toolchain' => [
                'image' => self::RUNNER_IMAGE,
                'fastlane' => '2.225.0',
                'xcode' => '16.2',
                'flutter' => '3.27.1',
            ],
            ...$overrides,
        ];
    }

    /**
     * The events an agent sends once the IPA is uploaded: the exit, both artifacts and the completion.
     *
     * @param array<string, mixed> $lease
     * @param array<string, mixed> $content
     * @return list<array<string, mixed>>
     */
    protected function closing(array $lease, array $content): array
    {
        return [
            $this->leaseEvent($lease, 'exit', $this->exitData(0)),
            $this->leaseEvent($lease, 'artifact', [
                'artifactId' => 'result',
                'artifactType' => 'read',
                'status' => 'success',
                'content' => $content,
                'format' => 'json',
                'durationSeconds' => 0.2,
            ]),
            $this->leaseEvent($lease, 'artifact', [
                'artifactId' => 'output',
                'artifactType' => 'upload',
                'status' => 'success',
                'durationSeconds' => 1.5,
            ]),
            $this->leaseEvent($lease, 'complete'),
        ];
    }

    /**
     * @param array{runnerId: string} $runner
     * @param array<string, mixed> $lease
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    protected function uploadChunk(array $runner, array $lease, string $chunk, int $start, int $size, string $artifactId = '', array $headers = []): array
    {
        $request = [
            'content-type' => 'multipart/form-data',
            'content-range' => 'bytes ' . $start . '-' . ($start + \strlen($chunk) - 1) . '/' . $size,
        ];
        if ($artifactId !== '') {
            $request['x-appwrite-id'] = $artifactId;
        }

        return $this->client->call(Client::METHOD_POST, $this->leasePath($runner, $lease, '/artifacts'), $this->leaseHeaders($lease, \array_merge($request, $headers)), [
            'file' => new \CURLFile('data://application/octet-stream;base64,' . \base64_encode($chunk), 'application/octet-stream', 'app.ipa'),
        ]);
    }

    /**
     * Uploads in exact 5 MiB chunks, the first alone without an ID, and returns the artifact ID.
     *
     * @param array{runnerId: string} $runner
     * @param array<string, mixed> $lease
     */
    protected function upload(array $runner, array $lease, string $ipa): string
    {
        $size = \strlen($ipa);
        $artifactId = '';
        $uploaded = [];

        for ($start = 0; $start < $size; $start += self::UPLOAD_CHUNK) {
            $uploaded = $this->uploadChunk($runner, $lease, \substr($ipa, $start, self::UPLOAD_CHUNK), $start, $size, $artifactId);
            $this->assertSame(202, $uploaded['headers']['status-code'], \json_encode($uploaded['body']));
            $artifactId = $uploaded['body']['$id'];
        }

        $chunks = (int) \ceil($size / self::UPLOAD_CHUNK);
        $this->assertSame($chunks, $uploaded['body']['chunksTotal']);
        $this->assertSame($chunks, $uploaded['body']['chunksUploaded']);

        return $artifactId;
    }

    /**
     * Seconds since the epoch of an RFC 3339 timestamp, failing on any other shape.
     */
    protected function timestamp(mixed $value): float
    {
        $this->assertIsString($value);

        foreach ([\DATE_RFC3339_EXTENDED, \DATE_RFC3339, 'Y-m-d\TH:i:s.uP'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat($format, $value);
            if ($parsed !== false) {
                return (float) $parsed->format('U.u');
            }
        }

        $this->fail('Not an RFC 3339 timestamp: ' . $value);
    }

    /**
     * The same token with its MAC changed in the last character.
     */
    protected function tamper(string $token): string
    {
        return \substr($token, 0, -1) . (\substr($token, -1) === '0' ? '1' : '0');
    }

    /**
     * The build plane's callback key, which an untrusted build job holds.
     */
    protected function buildPlaneSecret(): string
    {
        $secret = System::getEnv('_APP_DISTRIBUTION_CALLBACK_SECRET', '');
        if ($secret !== '') {
            return $secret;
        }

        $jobsSecret = System::getEnv('_APP_JOBS_SECRET', '');
        $this->assertNotSame('', $jobsSecret, 'The jobs secret must be set in the test container.');

        return \hash_hmac('sha256', 'distribution:build', $jobsSecret);
    }

    /**
     * @return array{archive: string, password: string, certificate: Certificate}
     */
    private function iosIdentity(): array
    {
        $key = Certificate::key();
        $certificate = $this->iosCertificate($key, [
            'commonName' => 'Apple Distribution: Appwrite (' . self::RUNNER_TEAM . ')',
            'organizationalUnitName' => self::RUNNER_TEAM,
        ]);
        $password = \bin2hex(\random_bytes(12));

        return [
            'archive' => $certificate->export($key, $password, 'distribution'),
            'password' => $password,
            'certificate' => $certificate,
        ];
    }

    /**
     * @param array<string, string> $subject
     */
    private function iosCertificate(string $key, array $subject): Certificate
    {
        $signed = \openssl_csr_sign(Certificate::request($key, $subject), null, $key, 365, ['digest_alg' => 'sha256', 'config' => Certificate::CONFIG], \random_int(1, PHP_INT_MAX));
        $this->assertNotFalse($signed);

        $pem = null;
        $this->assertTrue(\openssl_x509_export($signed, $pem));
        $this->assertIsString($pem);

        return new Certificate($pem);
    }

    private function iosProfile(Certificate $certificate, string $identifier, int $expiresAt): string
    {
        $developer = \base64_encode($certificate->der());
        $expiration = \gmdate('Y-m-d\TH:i:s\Z', $expiresAt);
        $uuid = \vsprintf('%s%s-%s-%s-%s-%s%s%s', \str_split(\bin2hex(\random_bytes(16)), 4));
        $team = self::RUNNER_TEAM;

        $plist = <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <plist version="1.0">
            <dict>
            	<key>DeveloperCertificates</key><array><data>{$developer}</data></array>
            	<key>Entitlements</key><dict>
            		<key>application-identifier</key><string>{$team}.{$identifier}</string>
            		<key>com.apple.developer.team-identifier</key><string>{$team}</string>
            		<key>get-task-allow</key><false/>
            	</dict>
            	<key>ExpirationDate</key><date>{$expiration}</date>
            	<key>Name</key><string>Appwrite {$identifier}</string>
            	<key>TeamIdentifier</key><array><string>{$team}</string></array>
            	<key>UUID</key><string>{$uuid}</string>
            </dict>
            </plist>
            XML;

        $key = Certificate::key();
        $signer = $this->iosCertificate($key, ['commonName' => 'Apple iPhone OS Provisioning Profile Signing']);

        $input = \tempnam(\sys_get_temp_dir(), 'plist');
        $output = \tempnam(\sys_get_temp_dir(), 'profile');
        $this->assertIsString($input);
        $this->assertIsString($output);

        try {
            \file_put_contents($input, $plist);
            $this->assertTrue(\openssl_cms_sign($input, $output, $signer->pem, $key, null, OPENSSL_CMS_BINARY, OPENSSL_ENCODING_DER));

            $envelope = \file_get_contents($output);
            $this->assertIsString($envelope);

            return $envelope;
        } finally {
            \unlink($input);
            \unlink($output);
        }
    }
}
