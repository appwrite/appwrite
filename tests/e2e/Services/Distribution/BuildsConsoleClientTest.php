<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Distribution;

use Appwrite\ID;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideConsole;
use Tests\E2E\Services\VCSGitea\VCSGiteaBase;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Distribution\Signing\Keystore;
use Utopia\System\System;

final class BuildsConsoleClientTest extends Scope
{
    use DistributionBase;
    use BuildsBase;
    use GiteaRepositories;
    use MockStores;
    use VCSGiteaBase;
    use ProjectCustom;
    use SideConsole;

    private const string ALIAS = 'upload';

    public function testCreateBuildFromBranch(): void
    {
        $keystore = Keystore::generate(self::ALIAS, ['CN' => 'Appwrite Builds']);
        $identity = $this->createKeystoreIdentity($keystore);
        $this->assertSame(201, $identity['headers']['status-code']);
        $prepared = $this->prepareBuild(target: ['signingIdentityId' => $identity['body']['$id']]);
        $appId = $prepared['appId'];
        $head = $this->branchHead($prepared['repository']);

        /**
         * Test for SUCCESS
         */
        $created = $this->createBuild($appId, [
            'targetId' => $prepared['targetId'],
            'versionName' => '1.4.0',
        ]);
        $this->assertSame(202, $created['headers']['status-code'], \json_encode($created['body']));
        $this->assertSame($appId, $created['body']['appId']);
        $this->assertSame($prepared['targetId'], $created['body']['targetId']);
        $this->assertSame('android', $created['body']['platform']);
        $this->assertSame('manual', $created['body']['type']);
        $this->assertContains($created['body']['status'], ['waiting', 'processing', 'building']);
        $this->assertSame(1, $created['body']['attempt']);
        $this->assertSame(1, $created['body']['versionCode']);
        $this->assertSame('1.4.0', $created['body']['versionName']);
        $this->assertSame(self::GITEA_BRANCH, $created['body']['providerBranch']);
        $this->assertSame('', $created['body']['providerTag']);
        $this->assertSame($head, $created['body']['providerCommitHash']);
        $this->assertSame(self::GITEA_USERNAME, $created['body']['providerRepositoryOwner']);
        $this->assertSame($prepared['repository'], $created['body']['providerRepositoryName']);
        $this->assertSame('', $created['body']['artifactId']);

        $app = $this->client->call(Client::METHOD_GET, '/distribution/apps/' . $appId, $this->headers());
        $this->assertSame(200, $app['headers']['status-code']);
        $this->assertSame($created['body']['$id'], $app['body']['latestBuildId']);

        $ready = $this->awaitBuild($appId, $created['body']['$id'], ['ready']);
        $this->assertSame('', $ready['errorCode']);
        $this->assertNotEmpty($ready['artifactId']);
        $this->assertNotEmpty($ready['startedAt']);
        $this->assertNotEmpty($ready['endedAt']);
        $this->assertGreaterThan(0, $ready['logsSize']);

        $artifact = $this->client->call(Client::METHOD_GET, '/distribution/apps/' . $appId . '/artifacts/' . $ready['artifactId'], $this->headers());
        $this->assertSame(200, $artifact['headers']['status-code']);
        $this->assertSame('ready', $artifact['body']['status']);
        $this->assertSame('build', $artifact['body']['source']);
        $this->assertSame($ready['$id'], $artifact['body']['buildId']);
        $this->assertSame($prepared['targetId'], $artifact['body']['targetId']);
        $this->assertSame($prepared['identifier'], $artifact['body']['identifier']);
        $this->assertSame(1, $artifact['body']['versionCode']);
        $this->assertSame('1.4.0', $artifact['body']['versionName']);
        $this->assertNotEmpty($artifact['body']['name']);
        $this->assertTrue($artifact['body']['signed']);
        $this->assertSame($keystore->certificate->fingerprint(), $artifact['body']['fingerprint']);
        $this->assertGreaterThan(\time(), \strtotime($artifact['body']['expiresAt']));

        $this->assertEventually(function () use ($appId, $ready): void {
            $app = $this->client->call(Client::METHOD_GET, '/distribution/apps/' . $appId, $this->headers());
            $this->assertSame($ready['$id'], $app['body']['latestBuildId']);
            $this->assertSame('ready', $app['body']['latestBuildStatus']);
        }, 30_000, 1000);

        $second = $this->createBuild($appId, ['targetId' => $prepared['targetId']]);
        $this->assertSame(202, $second['headers']['status-code'], \json_encode($second['body']));
        $this->assertSame(2, $second['body']['versionCode']);
        $rebuilt = $this->awaitBuild($appId, $second['body']['$id'], ['ready']);
        $this->assertNotSame($ready['artifactId'], $rebuilt['artifactId']);

        $artifact = $this->client->call(Client::METHOD_GET, '/distribution/apps/' . $appId . '/artifacts/' . $rebuilt['artifactId'], $this->headers());
        $this->assertSame(200, $artifact['headers']['status-code']);
        $this->assertSame(2, $artifact['body']['versionCode']);
        $this->assertTrue($artifact['body']['signed']);

        $listed = $this->listBuilds($appId);
        $this->assertSame(200, $listed['headers']['status-code']);
        $this->assertSame(2, $listed['body']['total']);
        $this->assertEqualsCanonicalizing([$ready['$id'], $rebuilt['$id']], \array_column($listed['body']['builds'], '$id'));

        $filtered = $this->listBuilds($appId, [Query::equal('versionCode', [2])->toString()]);
        $this->assertSame(200, $filtered['headers']['status-code']);
        $this->assertSame(1, $filtered['body']['total']);
        $this->assertSame($rebuilt['$id'], $filtered['body']['builds'][0]['$id']);

        /**
         * Test for FAILURE
         */
        $branch = $this->createBuild($appId, [
            'targetId' => $prepared['targetId'],
            'reference' => 'missing-' . \uniqid(),
        ]);
        $this->assertSame(400, $branch['headers']['status-code']);
        $this->assertSame('distribution_reference_not_found', $branch['body']['type']);

        $target = $this->createBuild($appId, ['targetId' => 'missing-' . \uniqid()]);
        $this->assertSame(404, $target['headers']['status-code']);
        $this->assertSame('distribution_target_not_found', $target['body']['type']);

        $release = $this->createBuild($appId, [
            'targetId' => $prepared['targetId'],
            'releaseId' => 'missing-' . \uniqid(),
        ]);
        $this->assertSame(404, $release['headers']['status-code']);
        $this->assertSame('distribution_release_not_found', $release['body']['type']);

        $type = $this->createBuild($appId, [
            'targetId' => $prepared['targetId'],
            'type' => 'pull',
        ]);
        $this->assertSame(400, $type['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $type['body']['type']);

        $query = $this->listBuilds($appId, [Query::equal('errorMessage', ['x'])->toString()]);
        $this->assertSame(400, $query['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $query['body']['type']);

        $this->assertSame(2, $this->listBuilds($appId)['body']['total'], 'A refused build must not leave a row behind.');
    }

    public function testCreateBuildFromCommit(): void
    {
        $prepared = $this->prepareBuild();
        $appId = $prepared['appId'];
        $commits = $this->giteaApi(Client::METHOD_GET, '/api/v1/repos/' . self::GITEA_USERNAME . '/' . $prepared['repository'] . '/commits', [
            'sha' => self::GITEA_BRANCH,
        ]);
        $this->assertSame(200, $commits['headers']['status-code']);
        $this->assertGreaterThan(1, \count($commits['body']));
        $first = \end($commits['body'])['sha'];
        $this->assertNotSame($this->branchHead($prepared['repository']), $first);

        /**
         * Test for SUCCESS
         */
        $created = $this->createBuild($appId, [
            'targetId' => $prepared['targetId'],
            'type' => 'commit',
            'reference' => $first,
        ]);
        $this->assertSame(202, $created['headers']['status-code'], \json_encode($created['body']));
        $this->assertSame($first, $created['body']['providerCommitHash']);
        $this->assertSame('', $created['body']['providerBranch']);
        $this->assertSame('', $created['body']['providerTag']);

        $ready = $this->awaitBuild($appId, $created['body']['$id'], ['ready']);
        $this->assertSame($first, $ready['providerCommitHash']);
        $this->assertNotEmpty($ready['artifactId']);

        /**
         * Test for FAILURE
         */
        $missing = $this->createBuild($appId, [
            'targetId' => $prepared['targetId'],
            'type' => 'commit',
            'reference' => \str_repeat('0', 40),
        ]);
        $this->assertSame(400, $missing['headers']['status-code']);
        $this->assertSame('distribution_reference_not_found', $missing['body']['type']);

        $empty = $this->createBuild($appId, [
            'targetId' => $prepared['targetId'],
            'type' => 'commit',
        ]);
        $this->assertSame(400, $empty['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $empty['body']['type']);

        foreach (['main', 'abc12', \str_repeat('a', 65), 'g' . \substr($first, 1)] as $reference) {
            $invalid = $this->createBuild($appId, [
                'targetId' => $prepared['targetId'],
                'type' => 'commit',
                'reference' => $reference,
            ]);
            $this->assertSame(400, $invalid['headers']['status-code'], $reference);
            $this->assertSame('general_argument_invalid', $invalid['body']['type'], 'A commit reference is 7 to 64 hexadecimal characters: ' . $reference);
        }

        $this->assertSame(1, $this->listBuilds($appId)['body']['total'], 'A refused build must not leave a row behind.');
    }

    public function testCreateBuildFromTag(): void
    {
        $prepared = $this->prepareBuild();
        $appId = $prepared['appId'];
        $tag = 'v1.0.' . \random_int(0, 99999);
        $this->createGiteaTag($prepared['repository'], $tag);

        /**
         * Test for SUCCESS
         */
        $created = $this->createBuild($appId, [
            'targetId' => $prepared['targetId'],
            'type' => 'tag',
            'reference' => $tag,
        ]);
        $this->assertSame(202, $created['headers']['status-code'], \json_encode($created['body']));
        $this->assertSame($tag, $created['body']['providerTag']);
        $this->assertSame('', $created['body']['providerBranch']);
        $this->assertSame($this->branchHead($prepared['repository']), $created['body']['providerCommitHash']);

        $ready = $this->awaitBuild($appId, $created['body']['$id'], ['ready']);
        $this->assertNotEmpty($ready['artifactId']);

        /**
         * Test for FAILURE
         */
        $prefix = $this->createBuild($appId, [
            'targetId' => $prepared['targetId'],
            'type' => 'tag',
            'reference' => 'v1',
        ]);
        $this->assertSame(400, $prefix['headers']['status-code'], 'A tag is matched by its exact name, not a prefix.');
        $this->assertSame('distribution_reference_not_found', $prefix['body']['type']);
    }

    public function testBuildWithoutSigningIdentityIsUnsigned(): void
    {
        $prepared = $this->prepareBuild();
        $appId = $prepared['appId'];

        $created = $this->createBuild($appId, ['targetId' => $prepared['targetId']]);
        $this->assertSame(202, $created['headers']['status-code'], \json_encode($created['body']));

        /**
         * Test for SUCCESS
         */
        $ready = $this->awaitBuild($appId, $created['body']['$id'], ['ready']);
        $artifact = $this->client->call(Client::METHOD_GET, '/distribution/apps/' . $appId . '/artifacts/' . $ready['artifactId'], $this->headers());
        $this->assertSame(200, $artifact['headers']['status-code']);
        $this->assertSame('ready', $artifact['body']['status']);
        $this->assertFalse($artifact['body']['signed']);
        $this->assertSame('', $artifact['body']['fingerprint']);
        $this->assertSame($prepared['identifier'], $artifact['body']['identifier']);
    }

    public function testListBuildLogsInOrder(): void
    {
        $prepared = $this->prepareBuild();
        $appId = $prepared['appId'];
        $this->createAppVariable($appId, 'FIXTURE_LOG_LINES', '5');

        $created = $this->createBuild($appId, ['targetId' => $prepared['targetId']]);
        $this->assertSame(202, $created['headers']['status-code'], \json_encode($created['body']));
        $buildId = $created['body']['$id'];
        $this->awaitBuild($appId, $buildId, ['ready']);

        /**
         * Test for SUCCESS
         */
        $rows = [];
        $this->assertEventually(function () use ($appId, $buildId, &$rows): void {
            $logs = $this->listBuildLogs($appId, $buildId, [Query::limit(100)->toString()]);
            $this->assertSame(200, $logs['headers']['status-code']);
            $this->assertGreaterThan(0, $logs['body']['total']);
            $rows = $logs['body']['logs'];
            $this->assertCount($logs['body']['total'], $rows);
            $this->assertSame([$buildId], \array_values(\array_unique(\array_column($rows, 'buildId'))));

            for ($i = 1; $i < \count($rows); $i++) {
                $previous = [$rows[$i - 1]['sequence'], $rows[$i - 1]['chunk']];
                $current = [$rows[$i]['sequence'], $rows[$i]['chunk']];
                $this->assertLessThan(0, $previous <=> $current, 'Logs are ordered by sequence, then chunk.');
            }

            $output = \implode('', \array_column(\array_filter($rows, fn (array $row): bool => $row['stage'] === 'build'), 'content'));
            $offset = 0;
            for ($line = 1; $line <= 5; $line++) {
                $position = \strpos($output, "fixture: log line {$line} of 5", $offset);
                $this->assertNotFalse($position, "Log line {$line} is missing or out of order: {$output}");
                $offset = $position;
            }
        }, 30_000, 1000);

        $staged = $this->listBuildLogs($appId, $buildId, [Query::equal('stage', ['build'])->toString(), Query::limit(100)->toString()]);
        $this->assertSame(200, $staged['headers']['status-code']);
        $this->assertGreaterThan(0, $staged['body']['total']);
        $this->assertSame(['build'], \array_values(\array_unique(\array_column($staged['body']['logs'], 'stage'))));

        $descending = [];
        $cursor = '';
        do {
            $page = $this->listBuildLogs($appId, $buildId, [
                Query::orderDesc('sequence')->toString(),
                Query::orderDesc('chunk')->toString(),
                Query::limit(2)->toString(),
                ...($cursor === '' ? [] : [Query::cursorAfter(new Document(['$id' => $cursor]))->toString()]),
            ]);
            $this->assertSame(200, $page['headers']['status-code'], \json_encode($page['body']));
            \array_push($descending, ...\array_column($page['body']['logs'], '$id'));
            $cursor = \end($page['body']['logs'])['$id'] ?? '';
        } while (\count($page['body']['logs']) === 2);
        $this->assertSame(\array_reverse(\array_column($rows, '$id')), $descending, 'A descending, cursor-paged read returns every row in reverse.');

        /**
         * Test for FAILURE
         */
        $attribute = $this->listBuildLogs($appId, $buildId, [Query::equal('content', ['x'])->toString()]);
        $this->assertSame(400, $attribute['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $attribute['body']['type']);

        $unknownCursor = $this->listBuildLogs($appId, $buildId, [Query::cursorAfter(new Document(['$id' => 'missing']))->toString()]);
        $this->assertSame(400, $unknownCursor['headers']['status-code']);
        $this->assertSame('general_cursor_not_found', $unknownCursor['body']['type']);
    }

    public function testFailedBuildMapsExitCode(): void
    {
        $prepared = $this->prepareBuild();
        $appId = $prepared['appId'];
        $this->createAppVariable($appId, 'FIXTURE_EXIT_CODE', '4');

        $created = $this->createBuild($appId, ['targetId' => $prepared['targetId']]);
        $this->assertSame(202, $created['headers']['status-code'], \json_encode($created['body']));

        /**
         * Test for FAILURE
         */
        $failed = $this->awaitBuild($appId, $created['body']['$id'], ['failed']);
        $this->assertSame('build_keystore_required', $failed['errorCode']);
        $this->assertNotEmpty($failed['errorMessage']);
        $this->assertNotEmpty($failed['endedAt']);
        $this->assertSame('', $failed['artifactId']);

        $this->assertEventually(function () use ($appId): void {
            $app = $this->client->call(Client::METHOD_GET, '/distribution/apps/' . $appId, $this->headers());
            $this->assertSame('failed', $app['body']['latestBuildStatus']);
        }, 30_000, 1000);
    }

    public function testBuildRefusesAForeignIdentifier(): void
    {
        $prepared = $this->prepareBuild();
        $appId = $prepared['appId'];
        $this->createAppVariable($appId, 'FIXTURE_IDENTIFIER', 'io.appwrite.foreign' . \uniqid());

        $created = $this->createBuild($appId, ['targetId' => $prepared['targetId']]);
        $this->assertSame(202, $created['headers']['status-code'], \json_encode($created['body']));

        /**
         * Test for FAILURE
         */
        $failed = $this->awaitBuild($appId, $created['body']['$id'], ['failed']);
        $this->assertSame('build_output_invalid', $failed['errorCode']);
        $this->assertSame('', $failed['artifactId']);

        $artifacts = $this->client->call(Client::METHOD_GET, '/distribution/apps/' . $appId . '/artifacts', $this->headers());
        $this->assertSame(200, $artifacts['headers']['status-code']);
        $this->assertSame(0, $artifacts['body']['total'], 'A bundle for another package must not become an artifact.');
    }

    public function testBuildLogsMaskSecretVariables(): void
    {
        $prepared = $this->prepareBuild();
        $appId = $prepared['appId'];
        $secret = 'io.appwrite.secret' . \uniqid();
        $this->createAppVariable($appId, 'FIXTURE_IDENTIFIER', $secret, true);

        $created = $this->createBuild($appId, ['targetId' => $prepared['targetId']]);
        $this->assertSame(202, $created['headers']['status-code'], \json_encode($created['body']));
        $buildId = $created['body']['$id'];
        $this->awaitBuild($appId, $buildId, ['failed']);

        /**
         * Test for SUCCESS
         */
        $output = '';
        $this->assertEventually(function () use ($appId, $buildId, &$output): void {
            $logs = $this->listBuildLogs($appId, $buildId, [Query::limit(100)->toString()]);
            $this->assertSame(200, $logs['headers']['status-code']);
            $output = \implode('', \array_column($logs['body']['logs'], 'content'));
            $this->assertStringContainsString('fixture: ********, image', $output, 'The fixture prints the identifier it builds, masked.');
        }, 30_000, 1000);

        $this->assertStringNotContainsString($secret, $output, 'A secret variable never reaches the build logs.');
    }

    public function testCancelBuild(): void
    {
        $prepared = $this->prepareBuild();
        $appId = $prepared['appId'];
        $this->createAppVariable($appId, 'FIXTURE_SLEEP_SECONDS', '300');

        $created = $this->createBuild($appId, ['targetId' => $prepared['targetId']]);
        $this->assertSame(202, $created['headers']['status-code'], \json_encode($created['body']));
        $buildId = $created['body']['$id'];
        $this->awaitBuild($appId, $buildId, ['building']);

        /**
         * Test for FAILURE
         */
        $ready = $this->updateBuildStatus($appId, $buildId, 'ready');
        $this->assertSame(400, $ready['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $ready['body']['type']);

        /**
         * Test for SUCCESS
         */
        $canceled = $this->updateBuildStatus($appId, $buildId, 'canceled');
        $this->assertSame(200, $canceled['headers']['status-code'], \json_encode($canceled['body']));
        $this->assertSame('canceled', $canceled['body']['status']);
        $this->assertSame('build_canceled', $canceled['body']['errorCode']);
        $this->assertNotEmpty($canceled['body']['endedAt']);

        $fetched = $this->getBuild($appId, $buildId);
        $this->assertSame('canceled', $fetched['body']['status']);

        $this->assertEventually(function () use ($appId): void {
            $app = $this->client->call(Client::METHOD_GET, '/distribution/apps/' . $appId, $this->headers());
            $this->assertSame('canceled', $app['body']['latestBuildStatus']);
        }, 120_000, 2000);

        /**
         * Test for FAILURE
         */
        $again = $this->updateBuildStatus($appId, $buildId, 'canceled');
        $this->assertSame(409, $again['headers']['status-code']);
        $this->assertSame('distribution_build_transition_invalid', $again['body']['type']);
    }

    public function testDeleteBuild(): void
    {
        $prepared = $this->prepareBuild();
        $appId = $prepared['appId'];
        $created = $this->createBuild($appId, ['targetId' => $prepared['targetId']]);
        $this->assertSame(202, $created['headers']['status-code'], \json_encode($created['body']));
        $buildId = $created['body']['$id'];
        $ready = $this->awaitBuild($appId, $buildId, ['ready']);

        /**
         * Test for SUCCESS
         */
        $deleted = $this->deleteBuild($appId, $buildId);
        $this->assertSame(204, $deleted['headers']['status-code']);

        $fetched = $this->getBuild($appId, $buildId);
        $this->assertSame(404, $fetched['headers']['status-code']);
        $this->assertSame('distribution_build_not_found', $fetched['body']['type']);

        $artifact = $this->client->call(Client::METHOD_GET, '/distribution/apps/' . $appId . '/artifacts/' . $ready['artifactId'], $this->headers());
        $this->assertSame(404, $artifact['headers']['status-code'], 'An artifact no submission references goes with its build.');

        $this->assertSame(0, $this->listBuilds($appId)['body']['total']);

        /**
         * Test for FAILURE
         */
        $again = $this->deleteBuild($appId, $buildId);
        $this->assertSame(404, $again['headers']['status-code']);
        $this->assertSame('distribution_build_not_found', $again['body']['type']);
    }

    public function testDeleteRunningBuildCancelsIt(): void
    {
        $prepared = $this->prepareBuild();
        $appId = $prepared['appId'];
        $this->createAppVariable($appId, 'FIXTURE_SLEEP_SECONDS', '300');
        $created = $this->createBuild($appId, ['targetId' => $prepared['targetId']]);
        $this->assertSame(202, $created['headers']['status-code'], \json_encode($created['body']));
        $buildId = $created['body']['$id'];
        $this->awaitBuild($appId, $buildId, ['building']);

        /**
         * Test for SUCCESS
         */
        $deleted = $this->deleteBuild($appId, $buildId);
        $this->assertSame(204, $deleted['headers']['status-code']);
        $this->assertSame(404, $this->getBuild($appId, $buildId)['headers']['status-code']);

        $app = $this->client->call(Client::METHOD_GET, '/distribution/apps/' . $appId, $this->headers());
        $this->assertSame(200, $app['headers']['status-code']);
        $this->assertSame($buildId, $app['body']['latestBuildId']);
        $this->assertSame('canceled', $app['body']['latestBuildStatus'], 'Deleting a running build settles it.');

        $this->assertEventually(function () use ($prepared, $created): void {
            $states = $this->commitStates($prepared['repository'], $created['body']['providerCommitHash'], $prepared['appName']);
            $this->assertSame('error', \end($states), 'The commit check of a deleted running build is canceled, which Gitea shows as an error.');
        }, 30_000, 1000);

        if (System::getEnv('_APP_USAGE_STATS', 'enabled') === 'disabled') {
            return;
        }

        $this->assertUsage($appId, [
            METRIC_DISTRIBUTION_BUILDS => 1,
            METRIC_DISTRIBUTION_BUILDS_SUCCESS => 0,
            METRIC_DISTRIBUTION_BUILDS_FAILED => 1,
        ]);
    }

    public function testDeleteBuildKeepsASubmittedArtifact(): void
    {
        $serviceAccount = $this->seedGoogleServiceAccount();
        $connection = $this->createGoogleConnection(['serviceAccount' => $serviceAccount]);
        $this->assertSame(201, $connection['headers']['status-code']);
        $identity = $this->createKeystoreIdentity(Keystore::generate(self::ALIAS, ['CN' => 'Appwrite Builds']));
        $this->assertSame(201, $identity['headers']['status-code']);
        $prepared = $this->prepareBuild(['framework' => 'android'], [
            'connectionId' => $connection['body']['$id'],
            'signingIdentityId' => $identity['body']['$id'],
            'track' => 'internal',
        ]);
        $appId = $prepared['appId'];
        $this->seedGoogleApplication($prepared['identifier'], $serviceAccount['client_email']);

        $created = $this->createBuild($appId, ['targetId' => $prepared['targetId']]);
        $this->assertSame(202, $created['headers']['status-code'], \json_encode($created['body']));
        $buildId = $created['body']['$id'];
        $ready = $this->awaitBuild($appId, $buildId, ['ready']);
        $release = $this->createRelease($appId);
        $this->assertSame(201, $release['headers']['status-code']);

        $submission = $this->createSubmission($appId, [
            'releaseId' => $release['body']['$id'],
            'targetId' => $prepared['targetId'],
            'artifactId' => $ready['artifactId'],
            'track' => 'internal',
        ]);
        $this->assertSame(201, $submission['headers']['status-code'], \json_encode($submission['body']));

        /**
         * Test for SUCCESS
         */
        $deleted = $this->deleteBuild($appId, $buildId);
        $this->assertSame(204, $deleted['headers']['status-code']);
        $this->assertSame(404, $this->getBuild($appId, $buildId)['headers']['status-code']);

        $artifactPath = '/distribution/apps/' . $appId . '/artifacts/' . $ready['artifactId'];
        $artifact = $this->client->call(Client::METHOD_GET, $artifactPath, $this->headers());
        $this->assertSame(200, $artifact['headers']['status-code'], 'An artifact a submission references outlives its build.');

        $this->awaitSubmission($appId, $submission['body']['$id'], 'published');

        $artifact = $this->client->call(Client::METHOD_GET, $artifactPath, $this->headers());
        $this->assertSame(200, $artifact['headers']['status-code'], 'A terminal submission still keeps its artifact.');
        $this->assertSame('ready', $artifact['body']['status']);
    }

    public function testCreateBuildKeyIsIdempotent(): void
    {
        $prepared = $this->prepareBuild();
        $appId = $prepared['appId'];
        $key = 'release-' . \uniqid();
        $webhook = $this->client->call(Client::METHOD_POST, '/webhooks', $this->headers(), [
            'webhookId' => ID::unique(),
            'name' => 'Builds ' . \uniqid(),
            'events' => ['distributionApps.' . $appId . '.builds.*.create'],
            'url' => 'http://request-catcher-webhook:5000/',
            'tls' => false,
        ]);
        $this->assertSame(201, $webhook['headers']['status-code'], \json_encode($webhook['body']));
        $webhookId = $webhook['body']['$id'];

        /**
         * Test for SUCCESS
         */
        $first = $this->createBuild($appId, [
            'targetId' => $prepared['targetId'],
            'key' => $key,
        ]);
        $this->assertSame(202, $first['headers']['status-code'], \json_encode($first['body']));
        $event = 'distributionApps.' . $appId . '.builds.' . $first['body']['$id'] . '.create';

        $this->assertEventually(function () use ($webhookId, $event): void {
            $this->assertCount(1, $this->webhookDeliveries($webhookId, $event));
        }, 30_000, 1000);

        $replay = $this->createBuild($appId, [
            'targetId' => $prepared['targetId'],
            'key' => $key,
            'versionName' => '9.9.9',
        ]);
        $this->assertSame(200, $replay['headers']['status-code'], \json_encode($replay['body']));
        $this->assertSame($first['body']['$id'], $replay['body']['$id']);
        $this->assertSame($first['body']['versionCode'], $replay['body']['versionCode']);
        $this->assertSame('', $replay['body']['versionName'], 'A replay returns the original build, whatever else it asks for.');

        $this->assertSame(1, $this->listBuilds($appId)['body']['total']);

        $this->assertStays(
            fn (): int => \count($this->webhookDeliveries($webhookId, $event)),
            1,
            'A replayed key fires no second create event',
            15_000,
        );

        $other = $this->prepareBuild();
        $elsewhere = $this->createBuild($other['appId'], [
            'targetId' => $other['targetId'],
            'key' => $key,
        ]);
        $this->assertSame(202, $elsewhere['headers']['status-code'], \json_encode($elsewhere['body']));
        $this->assertNotSame($first['body']['$id'], $elsewhere['body']['$id'], 'A key is scoped to its target.');

        /**
         * Test for FAILURE
         */
        $buildId = $first['body']['$id'];
        $crossed = [
            'get' => $this->getBuild($other['appId'], $buildId),
            'logs' => $this->listBuildLogs($other['appId'], $buildId),
            'cancel' => $this->updateBuildStatus($other['appId'], $buildId, 'canceled'),
            'delete' => $this->deleteBuild($other['appId'], $buildId),
        ];
        foreach ($crossed as $case => $response) {
            $this->assertSame(404, $response['headers']['status-code'], 'A build of another app is not found: ' . $case);
            $this->assertSame('distribution_build_not_found', $response['body']['type'], $case);
        }

        $kept = $this->getBuild($appId, $buildId);
        $this->assertSame(200, $kept['headers']['status-code']);
        $this->assertNotSame('canceled', $kept['body']['status'], 'Another app cannot cancel the build.');

        $this->awaitBuild($appId, $first['body']['$id'], ['ready']);
        $this->awaitBuild($other['appId'], $elsewhere['body']['$id'], ['ready']);
    }

    public function testCommitStatusFollowsTheBuild(): void
    {
        $prepared = $this->prepareBuild();
        $appId = $prepared['appId'];
        $created = $this->createBuild($appId, ['targetId' => $prepared['targetId']]);
        $this->assertSame(202, $created['headers']['status-code'], \json_encode($created['body']));
        $commit = $created['body']['providerCommitHash'];
        $this->awaitBuild($appId, $created['body']['$id'], ['ready']);

        /**
         * Test for SUCCESS
         */
        $this->assertEventually(function () use ($prepared, $commit): void {
            $states = $this->commitStates($prepared['repository'], $commit, $prepared['appName']);
            $this->assertContains('pending', $states, 'The commit check is pending while the build runs.');
            $this->assertSame('success', \end($states), 'The newest commit check of a ready build is a success.');
        }, 30_000, 1000);

        $silent = $this->prepareBuild(['providerStatuses' => false]);
        $quiet = $this->createBuild($silent['appId'], ['targetId' => $silent['targetId']]);
        $this->assertSame(202, $quiet['headers']['status-code'], \json_encode($quiet['body']));
        $this->awaitBuild($silent['appId'], $quiet['body']['$id'], ['ready']);

        $this->assertSame([], $this->commitStatuses($silent['repository'], $quiet['body']['providerCommitHash']), 'An app with providerStatuses off reports no commit check.');
    }

    public function testBuildIsMeteredOnce(): void
    {
        if (System::getEnv('_APP_USAGE_STATS', 'enabled') === 'disabled') {
            $this->markTestSkipped('Usage stats are disabled on this stack');
        }

        $prepared = $this->prepareBuild();
        $appId = $prepared['appId'];
        $created = $this->createBuild($appId, ['targetId' => $prepared['targetId']]);
        $this->assertSame(202, $created['headers']['status-code'], \json_encode($created['body']));
        $this->awaitBuild($appId, $created['body']['$id'], ['ready']);

        /**
         * Test for SUCCESS
         */
        $metered = [
            METRIC_DISTRIBUTION_BUILDS => 1,
            METRIC_DISTRIBUTION_BUILDS_SUCCESS => 1,
            METRIC_DISTRIBUTION_BUILDS_FAILED => 0,
        ];
        $this->assertUsage($appId, $metered);
        $this->assertUsageStays($appId, $metered);
    }
}
