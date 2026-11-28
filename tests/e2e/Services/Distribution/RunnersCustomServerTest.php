<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Distribution;

use Appwrite\ID;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Tests\E2E\Services\VCSGitea\VCSGiteaBase;

final class RunnersCustomServerTest extends Scope
{
    use DistributionBase;
    use GiteaRepositories;
    use RunnersBase;
    use VCSGiteaBase;
    use ProjectCustom;
    use SideServer;

    public function testCreateRunner(): void
    {
        $labels = ['xcode-' . \bin2hex(\random_bytes(8))];

        /**
         * Test for FAILURE
         */
        $missing = $this->enrolRunner('', $labels);
        $this->assertSame(401, $missing['headers']['status-code']);
        $this->assertSame('user_unauthorized', $missing['body']['type']);
        $this->assertArrayNotHasKey('token', $missing['body']);

        $guessed = $this->enrolRunner('Bearer ' . \bin2hex(\random_bytes(32)), $labels);
        $this->assertSame(401, $guessed['headers']['status-code']);
        $this->assertSame('user_unauthorized', $guessed['body']['type']);
        $this->assertArrayNotHasKey('token', $guessed['body']);

        $basic = $this->enrolRunner('Basic ' . \base64_encode('runner:' . $this->runnerSecret()), $labels);
        $this->assertSame(401, $basic['headers']['status-code'], 'Only a Bearer credential enrols.');

        $oversized = $this->enrolRunner('Bearer ' . $this->runnerSecret(), $labels, 3);
        $this->assertSame(400, $oversized['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $oversized['body']['type']);

        /**
         * Test for SUCCESS
         */
        $enrolled = $this->enrolRunner('Bearer ' . $this->runnerSecret(), $labels, 2);
        $this->assertSame(201, $enrolled['headers']['status-code'], \json_encode($enrolled['body']));
        $this->assertStringContainsString('no-store', (string) ($enrolled['headers']['cache-control'] ?? ''));
        $this->assertIsString($enrolled['body']['runnerId']);
        $this->assertNotSame('', $enrolled['body']['runnerId']);
        $this->assertIsString($enrolled['body']['token']);
        $this->assertNotSame('', $enrolled['body']['token']);
        $runner = [
            'runnerId' => $enrolled['body']['runnerId'],
            'token' => $enrolled['body']['token'],
            'labels' => $labels,
        ];

        $online = $this->beat($runner, 'online');
        $this->assertSame(200, $online['headers']['status-code'], \json_encode($online['body']));
        $this->assertSame($runner['runnerId'], $online['body']['runnerId']);
        $this->assertSame('online', $online['body']['status']);
        $this->assertGreaterThan(0, $this->timestamp($online['body']['seenAt']));

        $idle = $this->claim($runner);
        $this->assertSame(204, $idle['headers']['status-code'], 'Nothing is queued for labels no build uses.');
        $this->assertEmpty($idle['body']);

        $draining = $this->beat($runner, 'draining');
        $this->assertSame(200, $draining['headers']['status-code']);
        $this->assertSame('draining', $draining['body']['status']);

        $paused = $this->claim($runner);
        $this->assertSame(204, $paused['headers']['status-code'], 'A draining runner claims nothing.');

        /**
         * Test for FAILURE
         */
        $tampered = $this->beat($runner, 'online', $this->tamper($runner['token']));
        $this->assertSame(401, $tampered['headers']['status-code']);
        $this->assertSame('user_unauthorized', $tampered['body']['type']);

        $other = $this->enrol(['xcode-' . \bin2hex(\random_bytes(8))]);
        $crossed = $this->beat($runner, 'online', $other['token']);
        $this->assertSame(401, $crossed['headers']['status-code'], 'A runner token works only on its own runner.');
        $this->assertSame('user_unauthorized', $crossed['body']['type']);

        $forged = $this->claim($runner, $this->tamper($runner['token']));
        $this->assertSame(401, $forged['headers']['status-code']);
        $this->assertSame('user_unauthorized', $forged['body']['type']);

        $unknown = $this->beat($runner, 'busy');
        $this->assertSame(400, $unknown['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $unknown['body']['type']);

        $this->retire($runner);
        $this->retire($other);
    }

    public function testCreateRunnerLease(): void
    {
        $prepared = $this->prepareIosBuild();
        $appId = $prepared['appId'];
        $runner = $this->enrol($prepared['labels']);
        $first = $this->createIosBuild($prepared);
        $second = $this->createIosBuild($prepared);
        $builds = [
            $first['$id'] => $this->awaitDispatch($appId, $first['$id']),
            $second['$id'] => $this->awaitDispatch($appId, $second['$id']),
        ];

        /**
         * Test for SUCCESS
         */
        $claimed = $this->awaitClaim($runner);
        $this->assertSame(201, $claimed['headers']['status-code'], \json_encode($claimed['body']));
        $this->assertStringContainsString('no-store', (string) ($claimed['headers']['cache-control'] ?? ''));
        $lease = $claimed['body'];
        $this->assertArrayHasKey($lease['buildId'], $builds);
        $build = $builds[$lease['buildId']];

        $this->assertSame(36, \strlen($lease['leaseId']));
        $this->assertTrue(\ctype_xdigit($lease['leaseId']));
        $this->assertIsString($lease['token']);
        $this->assertNotSame('', $lease['token']);
        $this->assertGreaterThan(\time(), $this->timestamp($lease['expiresAt']));
        $this->assertStringEndsWith('/v1', $lease['endpoint']);
        $this->assertSame(['project' => $this->getProject()['$id']], $lease['query']);
        $this->assertSame(1, $lease['attempt']);
        $this->assertIsInt($lease['timeout']);
        $this->assertGreaterThan(0, $lease['timeout']);
        $this->assertSame('ios', $lease['platform']);
        $this->assertSame('flutter', $lease['framework']);
        $this->assertNotEmpty($build['providerCommitHash']);
        $this->assertSame($build['providerCommitHash'], $lease['source']['commit']);
        $this->assertSame('.', $lease['source']['rootDirectory']);
        $this->assertSame($prepared['identifier'], $lease['environment']['DISTRIBUTION_IDENTIFIER']);
        $this->assertNotEmpty($lease['commands']['install']);
        $this->assertNotEmpty($lease['commands']['build']);
        $this->assertSame($prepared['archive'], \base64_decode($lease['signing']['certificate'], true), 'The lease carries the uploaded p12.');
        $this->assertSame($prepared['password'], $lease['signing']['password']);
        $this->assertSame([$prepared['identifier']], \array_keys($lease['signing']['profiles']));
        $this->assertSame($prepared['profile'], \base64_decode($lease['signing']['profiles'][$prepared['identifier']], true));
        $this->assertSame(self::RUNNER_TEAM, $lease['signing']['teamId']);
        $this->assertNotSame('', $build['versionName']);
        $this->assertSame([
            'name' => $build['versionName'],
            'code' => (int) $build['buildNumber'],
        ], $lease['version']);

        $processing = $this->getBuild($appId, $lease['buildId']);
        $this->assertSame(200, $processing['headers']['status-code']);
        $this->assertSame('processing', $processing['body']['status']);
        $this->assertSame(1, $processing['body']['attempt']);

        /**
         * Test for FAILURE
         */
        $full = $this->claim($runner);
        $this->assertSame(204, $full['headers']['status-code'], 'A capacity-1 runner holding a lease claims nothing more.');

        $queuedId = $lease['buildId'] === $first['$id'] ? $second['$id'] : $first['$id'];
        $queued = $this->getBuild($appId, $queuedId);
        $this->assertSame('waiting', $queued['body']['status'], 'The unclaimed build stays queued.');

        $versionless = $this->prepareIosBuild();
        $guarded = $this->enrol($versionless['labels']);
        $created = $this->createBuild($versionless['appId'], ['targetId' => $versionless['targetId']]);
        $this->assertSame(202, $created['headers']['status-code'], \json_encode($created['body']));
        $this->assertSame('', $created['body']['versionName']);

        $failed = $this->awaitBuild($versionless['appId'], $created['body']['$id'], ['failed']);
        $this->assertSame('build_invalid', $failed['errorCode']);
        $this->assertStringContainsString('version name', (string) $failed['errorMessage']);
        $this->assertSame('', $failed['artifactId']);

        $nothing = $this->claim($guarded);
        $this->assertSame(204, $nothing['headers']['status-code'], 'A build failed at dispatch queues no lease.');

        $this->cancelBuild($appId, $first['$id']);
        $this->cancelBuild($appId, $second['$id']);
        $this->retire($runner);
        $this->retire($guarded);
    }

    public function testRunnerLeaseBuild(): void
    {
        $prepared = $this->prepareIosBuild();
        $appId = $prepared['appId'];
        $runner = $this->enrol($prepared['labels']);
        $build = $this->createIosBuild($prepared);
        $buildId = $build['$id'];
        $lease = $this->awaitLease($runner);
        $this->assertSame($buildId, $lease['buildId']);

        /**
         * Test for SUCCESS
         */
        $descriptor = $this->source($runner, $lease);
        $this->assertSame(200, $descriptor['headers']['status-code'], \json_encode($descriptor['body']));
        $this->assertStringContainsString('no-store', (string) ($descriptor['headers']['cache-control'] ?? ''));
        $this->assertSame('tarball', $descriptor['body']['format']);
        $this->assertNotEmpty($descriptor['body']['url']);

        $headers = [];
        foreach ($descriptor['body']['headers'] as $name => $value) {
            $headers[\strtolower((string) $name)] = (string) $value;
        }
        $download = new Client();
        $download->setEndpoint($descriptor['body']['url']);
        $archive = $download->call(Client::METHOD_GET, '', $headers, [], false);
        $this->assertSame(200, $archive['headers']['status-code']);
        $this->assertSame('1f8b', \bin2hex(\substr((string) $archive['body'], 0, 2)), 'The source is a gzip tarball.');

        $extended = $this->extend($runner, $lease);
        $this->assertSame(200, $extended['headers']['status-code'], \json_encode($extended['body']));
        $this->assertFalse($extended['body']['canceled']);
        $this->assertGreaterThan($this->timestamp($lease['expiresAt']), $this->timestamp($extended['body']['expiresAt']));

        $marker = 'Compiling ' . \uniqid();
        $log = $this->leaseEvent($lease, 'log', ['lines' => [$marker], 'stream' => 'stdout']);
        $opened = $this->postEvents($runner, $lease, [...$this->opening($lease), $log]);
        $this->assertSame(204, $opened['headers']['status-code'], \json_encode($opened['body']));
        $resent = $this->postEvents($runner, $lease, [$log]);
        $this->assertSame(204, $resent['headers']['status-code'], 'A batch resent after a lost answer is accepted.');

        $ipa = \random_bytes(6_000_000);
        $size = \strlen($ipa);
        $head = \substr($ipa, 0, self::UPLOAD_CHUNK);
        $tail = \substr($ipa, self::UPLOAD_CHUNK);

        $started = $this->uploadChunk($runner, $lease, $head, 0, $size);
        $this->assertSame(202, $started['headers']['status-code'], \json_encode($started['body']));
        $artifactId = $started['body']['$id'];
        $this->assertSame(2, $started['body']['chunksTotal']);
        $this->assertSame(1, $started['body']['chunksUploaded']);

        $retried = $this->uploadChunk($runner, $lease, $head, 0, $size);
        $this->assertSame(202, $retried['headers']['status-code'], \json_encode($retried['body']));
        $this->assertSame($artifactId, $retried['body']['$id'], 'A resent first chunk lands on the same artifact.');

        $completed = $this->uploadChunk($runner, $lease, $tail, self::UPLOAD_CHUNK, $size, $artifactId);
        $this->assertSame(202, $completed['headers']['status-code'], \json_encode($completed['body']));
        $this->assertSame($artifactId, $completed['body']['$id']);
        $this->assertSame(2, $completed['body']['chunksTotal']);
        $this->assertSame(2, $completed['body']['chunksUploaded']);

        $closed = $this->postEvents($runner, $lease, $this->closing($lease, $this->outcome($prepared, $build, $ipa)));
        $this->assertSame(204, $closed['headers']['status-code'], \json_encode($closed['body']));

        $ready = $this->awaitBuild($appId, $buildId, ['ready']);
        $this->assertSame($artifactId, $ready['artifactId']);
        $this->assertSame($build['versionName'], $ready['versionName']);
        $this->assertSame('16.2', $ready['toolchain']['xcode'] ?? null, 'The runner toolchain is recorded on the build.');

        $artifact = $this->getArtifact($appId, $artifactId);
        $this->assertSame(200, $artifact['headers']['status-code']);
        $this->assertSame('ready', $artifact['body']['status'], $artifact['body']['errorCode'] . ' ' . $artifact['body']['errorMessage']);
        $this->assertSame('', $artifact['body']['errorCode']);
        $this->assertSame('ipa', $artifact['body']['type']);
        $this->assertSame('ios', $artifact['body']['platform']);
        $this->assertSame('build', $artifact['body']['source']);
        $this->assertSame($buildId, $artifact['body']['buildId']);
        $this->assertSame($prepared['targetId'], $artifact['body']['targetId']);
        $this->assertSame(\hash('sha256', $ipa), $artifact['body']['digest']);
        $this->assertSame($size, $artifact['body']['size']);
        $this->assertSame($prepared['identifier'], $artifact['body']['identifier']);
        $this->assertSame($build['versionName'], $artifact['body']['versionName']);
        $this->assertSame($build['buildNumber'], $artifact['body']['buildNumber']);
        $this->assertSame(0, $artifact['body']['versionCode']);
        $this->assertTrue($artifact['body']['signed']);
        $this->assertSame($prepared['fingerprint'], $artifact['body']['fingerprint']);
        $this->assertSame(self::RUNNER_TEAM, $artifact['body']['manifest']['teamId']);
        $this->assertSame($prepared['profileExpiresAt'], \strtotime($artifact['body']['manifest']['profileExpiresAt']));

        $this->assertEventually(function () use ($appId, $buildId, $marker): void {
            $this->assertCount(1, $this->markedLogs($appId, $buildId, $marker), 'A resent log batch is stored once.');
        }, 60_000, 1000);

        $finished = $this->extend($runner, $lease);
        $this->assertSame(200, $finished['headers']['status-code']);
        $this->assertTrue($finished['body']['canceled'], 'A finished lease is not extended.');
        $this->timestamp($finished['body']['expiresAt']);

        $next = $this->createIosBuild($prepared);
        $again = $this->awaitLease($runner);
        $this->assertSame($next['$id'], $again['buildId'], 'A finished lease frees the capacity-1 runner.');
        $this->assertNotSame($lease['leaseId'], $again['leaseId']);

        /**
         * Test for FAILURE
         */
        $foreign = $this->uploadChunk($runner, $again, $tail, self::UPLOAD_CHUNK, $size, $artifactId);
        $this->assertSame(400, $foreign['headers']['status-code'], 'An upload cannot continue the artifact of another lease.');
        $this->assertSame('storage_invalid_appwrite_id', $foreign['body']['type']);

        $guessed = $this->uploadChunk($runner, $again, $tail, self::UPLOAD_CHUNK, $size, ID::unique());
        $this->assertSame(400, $guessed['headers']['status-code']);
        $this->assertSame('storage_invalid_appwrite_id', $guessed['body']['type']);

        $this->cancelBuild($appId, $next['$id']);
        $this->retire($runner);
    }

    public function testRunnerLeaseIsolation(): void
    {
        $prepared = $this->prepareIosBuild();
        $appId = $prepared['appId'];
        $runner = $this->enrol($prepared['labels'], 2);
        $first = $this->createIosBuild($prepared);
        $second = $this->createIosBuild($prepared);
        $leases = \array_column([$this->awaitLease($runner), $this->awaitLease($runner)], null, 'buildId');
        $this->assertArrayHasKey($first['$id'], $leases);
        $this->assertArrayHasKey($second['$id'], $leases);
        $leaseA = $leases[$first['$id']];
        $leaseB = $leases[$second['$id']];
        $this->assertNotSame($leaseA['leaseId'], $leaseB['leaseId']);

        $marker = 'Isolated ' . \uniqid();
        $routes = [
            'extend' => fn (array $headers): array => $this->extend($runner, $leaseA, $headers),
            'source' => fn (array $headers): array => $this->source($runner, $leaseA, $headers),
            'events' => fn (array $headers): array => $this->postEvents($runner, $leaseA, [$this->leaseEvent($leaseA, 'log', ['lines' => [$marker], 'stream' => 'stdout'])], $headers),
            'artifacts' => fn (array $headers): array => $this->uploadChunk($runner, $leaseA, 'ipa', 0, 3, '', $headers),
            'status' => fn (array $headers): array => $this->requeue($runner, $leaseA, $headers),
        ];
        $credentials = [
            'the token of another lease' => ['authorization' => 'Bearer ' . $leaseB['token']],
            'a tampered token' => ['authorization' => 'Bearer ' . $this->tamper($leaseA['token'])],
            'the runner token' => ['authorization' => 'Bearer ' . $runner['token']],
            'no token' => ['authorization' => ''],
        ];

        /**
         * Test for FAILURE
         */
        foreach ($credentials as $credential => $headers) {
            foreach ($routes as $route => $call) {
                $refused = $call($headers);
                $this->assertSame(401, $refused['headers']['status-code'], $route . ' with ' . $credential);
                $this->assertIsArray($refused['body'], $route . ' with ' . $credential);
                $this->assertSame('user_unauthorized', $refused['body']['type'] ?? '', $route . ' with ' . $credential);
                $this->assertArrayNotHasKey('url', $refused['body'], $route . ' with ' . $credential);
            }
        }

        $claimed = $this->claim($runner, $leaseA['token']);
        $this->assertSame(401, $claimed['headers']['status-code'], 'A lease token cannot claim.');
        $this->assertSame('user_unauthorized', $claimed['body']['type']);

        $foreignProjectId = $this->getProject(true)['$id'];
        foreach ($routes as $route => $call) {
            $hidden = $call(['x-appwrite-project' => $foreignProjectId]);
            $this->assertSame(404, $hidden['headers']['status-code'], $route . ' in another project');
            $this->assertSame('distribution_lease_not_found', $hidden['body']['type'] ?? '', $route . ' in another project');
        }

        /**
         * Test for SUCCESS
         */
        foreach ([$leaseA, $leaseB] as $lease) {
            $alive = $this->extend($runner, $lease);
            $this->assertSame(200, $alive['headers']['status-code'], \json_encode($alive['body']));
            $this->assertFalse($alive['body']['canceled'], 'A refused request leaves every lease held.');
        }

        foreach ([$first, $second] as $build) {
            $unchanged = $this->getBuild($appId, $build['$id']);
            $this->assertSame('processing', $unchanged['body']['status']);
            $this->assertSame(1, $unchanged['body']['attempt']);
        }

        $artifacts = $this->client->call(Client::METHOD_GET, '/distribution/apps/' . $appId . '/artifacts', $this->headers());
        $this->assertSame(200, $artifacts['headers']['status-code']);
        $this->assertSame(0, $artifacts['body']['total'], 'A refused upload stores nothing.');
        $this->assertSame([], $this->markedLogs($appId, $first['$id'], $marker), 'A refused event stores nothing.');

        $this->cancelBuild($appId, $first['$id']);
        $this->cancelBuild($appId, $second['$id']);
        $this->retire($runner);
    }

    public function testUpdateRunnerLeaseStatus(): void
    {
        $prepared = $this->prepareIosBuild();
        $appId = $prepared['appId'];
        $runner = $this->enrol($prepared['labels']);
        $build = $this->createIosBuild($prepared);
        $buildId = $build['$id'];
        $first = $this->awaitLease($runner);
        $this->assertSame(1, $first['attempt']);

        /**
         * Test for SUCCESS
         */
        $requeued = $this->requeue($runner, $first);
        $this->assertSame(204, $requeued['headers']['status-code'], \json_encode($requeued['body']));

        $expired = $this->extend($runner, $first);
        if ($expired['headers']['status-code'] === 404) {
            $this->assertSame('distribution_lease_not_found', $expired['body']['type'], 'An expired lease the build still holds is lost, not canceled.');
        } else {
            $this->assertSame(200, $expired['headers']['status-code'], \json_encode($expired['body']));
            $this->assertTrue($expired['body']['canceled'], 'Once the build is queued again, the old lease is canceled.');
        }

        $second = $this->awaitLease($runner);
        $this->assertSame($buildId, $second['buildId']);
        $this->assertNotSame($first['leaseId'], $second['leaseId']);
        $this->assertSame(2, $second['attempt']);

        $stale = $this->extend($runner, $first);
        $this->assertSame(200, $stale['headers']['status-code'], \json_encode($stale['body']));
        $this->assertTrue($stale['body']['canceled'], 'A requeued lease tells its holder to stop.');
        $this->timestamp($stale['body']['expiresAt']);

        /**
         * Test for FAILURE
         */
        $revived = $this->requeue($runner, $first);
        $this->assertSame(204, $revived['headers']['status-code']);

        $still = $this->extend($runner, $first);
        $this->assertTrue($still['body']['canceled'], 'The old holder cannot revive its lease.');

        $source = $this->source($runner, $first);
        $this->assertSame(404, $source['headers']['status-code']);
        $this->assertSame('distribution_lease_not_found', $source['body']['type']);

        $current = $this->extend($runner, $second);
        $this->assertFalse($current['body']['canceled'], 'Requeueing an old lease leaves the current one held.');

        $unchanged = $this->getBuild($appId, $buildId);
        $this->assertSame('processing', $unchanged['body']['status']);
        $this->assertSame(2, $unchanged['body']['attempt']);

        $this->assertSame(204, $this->requeue($runner, $second)['headers']['status-code']);
        $third = $this->awaitLease($runner);
        $this->assertSame($buildId, $third['buildId']);
        $this->assertSame(3, $third['attempt']);

        $this->assertSame(204, $this->requeue($runner, $third)['headers']['status-code']);
        $lost = $this->awaitBuild($appId, $buildId, ['failed']);
        $this->assertSame('runner_lost', $lost['errorCode']);
        $this->assertNotEmpty($lost['errorMessage']);
        $this->assertSame(3, $lost['attempt']);

        $released = $this->requeue($runner, $third);
        $this->assertSame(204, $released['headers']['status-code'], 'Requeueing a released lease is acknowledged.');

        $settled = $this->getBuild($appId, $buildId);
        $this->assertSame('failed', $settled['body']['status']);
        $this->assertSame('runner_lost', $settled['body']['errorCode']);
        $this->assertSame(3, $settled['body']['attempt']);

        $this->retire($runner);
    }

    public function testRunnerLeaseRetriesUpload(): void
    {
        $prepared = $this->prepareIosBuild();
        $appId = $prepared['appId'];
        $runner = $this->enrol($prepared['labels']);
        $build = $this->createIosBuild($prepared);
        $buildId = $build['$id'];
        $first = $this->awaitLease($runner);

        $failed = $this->postEvents($runner, $first, [
            ...$this->opening($first),
            $this->leaseEvent($first, 'log', ['lines' => ['Archiving'], 'stream' => 'stdout']),
            $this->leaseEvent($first, 'exit', $this->exitData(-1, 'upload')),
            $this->leaseEvent($first, 'artifact', $this->failedArtifact('output', 'upload', 'upload_failed')),
        ]);
        $this->assertSame(204, $failed['headers']['status-code'], \json_encode($failed['body']));

        /**
         * Test for SUCCESS
         */
        $this->assertEventually(function () use ($runner, $first): void {
            $released = $this->extend($runner, $first);
            $this->assertSame(200, $released['headers']['status-code'], \json_encode($released['body']));
            $this->assertTrue($released['body']['canceled'], 'The exit releases the lease, freeing the runner before the next reconcile.');
        }, self::QUIET_WINDOW, 500);

        $second = $this->awaitLease($runner);
        $this->assertSame($buildId, $second['buildId'], 'A failed upload queues the build again.');
        $this->assertNotSame($first['leaseId'], $second['leaseId']);
        $this->assertSame(2, $second['attempt']);

        $retried = $this->getBuild($appId, $buildId);
        $this->assertSame('processing', $retried['body']['status']);
        $this->assertSame(2, $retried['body']['attempt']);
        $this->assertSame('', $retried['body']['errorCode']);

        /**
         * Test for FAILURE
         */
        $stale = $this->extend($runner, $first);
        $this->assertSame(200, $stale['headers']['status-code']);
        $this->assertTrue($stale['body']['canceled'], 'The lease of the failed attempt is not extended.');

        $canceled = $this->postEvents($runner, $second, [
            ...$this->opening($second),
            $this->leaseEvent($second, 'exit', $this->exitData(-1, 'canceled')),
        ]);
        $this->assertSame(204, $canceled['headers']['status-code'], \json_encode($canceled['body']));

        $third = $this->awaitLease($runner);
        $this->assertSame($buildId, $third['buildId'], 'A runner told to stop a lease it no longer held queues the build again.');
        $this->assertSame(3, $third['attempt']);

        $requeued = $this->getBuild($appId, $buildId);
        $this->assertSame('processing', $requeued['body']['status']);
        $this->assertSame('', $requeued['body']['errorCode']);

        $this->cancelBuild($appId, $buildId);
        $this->retire($runner);
    }

    public function testRunnerLeaseFailures(): void
    {
        $prepared = $this->prepareIosBuild();
        $appId = $prepared['appId'];
        $runner = $this->enrol($prepared['labels']);
        $cases = [
            'timeout' => [[['exit', $this->exitData(137, 'timeout', 'job_failed')]], 'build_timeout'],
            'invalid project' => [[['exit', $this->exitData(2, 'build', 'job_exit_nonzero')]], 'build_invalid'],
            'unverified output' => [[
                ['exit', $this->exitData(-1, 'verification')],
                ['artifact', $this->failedArtifact('result', 'read', 'artifact_failed')],
            ], 'build_output_invalid'],
        ];

        /**
         * Test for FAILURE
         */
        foreach ($cases as $case => [$events, $code]) {
            $build = $this->createIosBuild($prepared);
            $lease = $this->awaitLease($runner);
            $this->assertSame($build['$id'], $lease['buildId'], $case);

            $posted = $this->postEvents($runner, $lease, [
                ...$this->opening($lease),
                ...\array_map(fn (array $event): array => $this->leaseEvent($lease, $event[0], $event[1]), $events),
            ]);
            $this->assertSame(204, $posted['headers']['status-code'], $case . ': ' . \json_encode($posted['body']));

            $failed = $this->awaitBuild($appId, $build['$id'], ['failed']);
            $this->assertSame($code, $failed['errorCode'], $case);
            $this->assertNotEmpty($failed['errorMessage'], $case);
            $this->assertSame('', $failed['artifactId'], $case);
        }

        $build = $this->createIosBuild($prepared);
        $lease = $this->awaitLease($runner);
        $ipa = \random_bytes(1024);
        $artifactId = $this->upload($runner, $lease, $ipa);
        $mismatched = $this->postEvents($runner, $lease, [
            ...$this->opening($lease),
            ...$this->closing($lease, $this->outcome($prepared, $build, $ipa, ['identifier' => 'io.appwrite.other' . \uniqid()])),
        ]);
        $this->assertSame(204, $mismatched['headers']['status-code'], \json_encode($mismatched['body']));

        $failed = $this->awaitBuild($appId, $build['$id'], ['failed']);
        $this->assertSame('build_output_invalid', $failed['errorCode'], 'An IPA for another bundle ID fails the build.');
        $this->assertEventually(function () use ($appId, $artifactId): void {
            $artifact = $this->getArtifact($appId, $artifactId);
            $this->assertSame(200, $artifact['headers']['status-code']);
            $this->assertSame('invalid', $artifact['body']['status']);
            $this->assertSame('build_output_invalid', $artifact['body']['errorCode']);
        }, 60_000, 1000);

        $build = $this->createIosBuild($prepared);
        $lease = $this->awaitLease($runner);
        $artifactId = $this->upload($runner, $lease, \random_bytes(1024));
        $exited = $this->postEvents($runner, $lease, [
            ...$this->opening($lease),
            $this->leaseEvent($lease, 'exit', $this->exitData(1, 'build', 'job_exit_nonzero')),
        ]);
        $this->assertSame(204, $exited['headers']['status-code'], \json_encode($exited['body']));

        $failed = $this->awaitBuild($appId, $build['$id'], ['failed']);
        $this->assertSame('build_failed', $failed['errorCode']);
        $this->assertSame('', $failed['artifactId']);
        $this->assertEventually(function () use ($appId, $artifactId): void {
            $artifact = $this->getArtifact($appId, $artifactId);
            $this->assertSame(200, $artifact['headers']['status-code']);
            $this->assertSame('invalid', $artifact['body']['status'], 'The upload of a failed build is invalidated.');
            $this->assertSame('build_failed', $artifact['body']['errorCode']);
        }, 60_000, 1000);

        $this->retire($runner);
    }

    public function testRunnerLeaseCanceled(): void
    {
        $prepared = $this->prepareIosBuild();
        $appId = $prepared['appId'];
        $runner = $this->enrol($prepared['labels']);
        $build = $this->createIosBuild($prepared);
        $buildId = $build['$id'];
        $lease = $this->awaitLease($runner);
        $this->assertSame(204, $this->postEvents($runner, $lease, $this->opening($lease))['headers']['status-code']);

        $canceled = $this->client->call(Client::METHOD_PATCH, '/distribution/apps/' . $appId . '/builds/' . $buildId . '/status', $this->headers(), [
            'status' => 'canceled',
        ]);
        $this->assertSame(200, $canceled['headers']['status-code'], \json_encode($canceled['body']));

        /**
         * Test for SUCCESS
         */
        $extended = $this->extend($runner, $lease);
        $this->assertSame(200, $extended['headers']['status-code'], \json_encode($extended['body']));
        $this->assertTrue($extended['body']['canceled'], 'Canceling the build tells the runner to stop.');
        $this->timestamp($extended['body']['expiresAt']);

        $ipa = \random_bytes(1024);
        $ignored = $this->postEvents($runner, $lease, $this->closing($lease, $this->outcome($prepared, $build, $ipa)));
        $this->assertSame(204, $ignored['headers']['status-code'], 'Events of a canceled lease are acknowledged.');

        /**
         * Test for FAILURE
         */
        $source = $this->source($runner, $lease);
        $this->assertSame(404, $source['headers']['status-code']);
        $this->assertSame('distribution_lease_not_found', $source['body']['type']);
        $this->assertArrayNotHasKey('url', $source['body']);

        $upload = $this->uploadChunk($runner, $lease, $ipa, 0, \strlen($ipa));
        $this->assertSame(404, $upload['headers']['status-code']);
        $this->assertSame('distribution_lease_not_found', $upload['body']['type']);

        $this->assertStays(
            fn (): array => [
                'status' => $this->getBuild($appId, $buildId)['body']['status'],
                'artifacts' => $this->client->call(Client::METHOD_GET, '/distribution/apps/' . $appId . '/artifacts', $this->headers())['body']['total'],
            ],
            ['status' => 'canceled', 'artifacts' => 0],
            'Events of a canceled lease are dropped',
            self::QUIET_WINDOW,
        );

        $this->retire($runner);
    }

    public function testRunnerLeaseRejectsBuildPlaneEvents(): void
    {
        $prepared = $this->prepareIosBuild();
        $appId = $prepared['appId'];
        $projectId = $this->getProject()['$id'];
        $runner = $this->enrol($prepared['labels']);
        $build = $this->createIosBuild($prepared);
        $buildId = $build['$id'];
        $lease = $this->awaitLease($runner);
        $marker = 'Forged ' . \uniqid();

        $forgeries = [
            'orchestrator.job.start' => [],
            'orchestrator.job.log' => ['lines' => [$marker], 'stream' => 'stdout'],
            'orchestrator.job.exit' => ['exitCode' => 137, 'reason' => 'timeout', 'image' => self::RUNNER_IMAGE, 'durationSeconds' => 1.0],
        ];

        /**
         * Test for FAILURE
         */
        foreach ($forgeries as $type => $data) {
            $event = [
                'specversion' => '1.0',
                'type' => $type,
                'source' => '/orchestrator',
                'subject' => $lease['leaseId'],
                'id' => $lease['leaseId'] . '-' . \hrtime(true),
                'time' => \gmdate('Y-m-d\TH:i:s\Z'),
                'datacontenttype' => 'application/json',
                'data' => [
                    ...$data,
                    'jobId' => $lease['leaseId'],
                    'meta' => [
                        'projectId' => $projectId,
                        'buildId' => $buildId,
                        'stage' => 'build',
                        'attempt' => '1',
                    ],
                ],
            ];

            $forged = $this->client->call(Client::METHOD_POST, '/distribution/events?project=' . $projectId, [
                'content-type' => 'application/json',
                'x-signature-256' => 'sha256=' . \hash_hmac('sha256', (string) \json_encode($event), $this->buildPlaneSecret()),
            ], $event);
            $this->assertSame(204, $forged['headers']['status-code'], $type);
            $this->assertEmpty($forged['body'], $type);
        }

        $this->assertStays(
            function () use ($appId, $buildId, $marker): array {
                $build = $this->getBuild($appId, $buildId)['body'];

                return [
                    'status' => $build['status'],
                    'errorCode' => $build['errorCode'],
                    'logs' => \count($this->markedLogs($appId, $buildId, $marker)),
                ];
            },
            ['status' => 'processing', 'errorCode' => '', 'logs' => 0],
            'A build-plane event cannot drive a leased build',
            self::QUIET_WINDOW,
        );

        /**
         * Test for SUCCESS
         */
        $extended = $this->extend($runner, $lease);
        $this->assertSame(200, $extended['headers']['status-code']);
        $this->assertFalse($extended['body']['canceled'], 'The lease is still held by its runner.');

        $this->cancelBuild($appId, $buildId);
        $this->retire($runner);
    }
}
