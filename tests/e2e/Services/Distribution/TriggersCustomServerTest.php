<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Distribution;

use Appwrite\ID;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Tests\E2E\Services\VCSGitea\VCSGiteaBase;
use Utopia\Database\Query;
use Utopia\Distribution\Signing\Keystore;
use Utopia\System\System;

final class TriggersCustomServerTest extends Scope
{
    use DistributionBase;
    use BuildsBase;
    use GiteaRepositories;
    use MockStores;
    use VCSGiteaBase;
    use ProjectCustom;
    use SideServer;

    private const int DELIVERY = 60_000;

    private const int QUIET = 20_000;

    private const int RECONCILE = 75_000;

    private const string HEADS = 'refs/heads/';

    private const string TAGS = 'refs/tags/';

    private const float FRACTION = 0.5;

    public function testTriggerBuildsFromPush(): void
    {
        $repository = $this->prepareRepository();
        $linked = $this->linkApp($repository);
        $appId = $linked['appId'];
        $ios = $this->createTarget($appId, ['platform' => 'ios', 'identifier' => 'io.appwrite.trigger' . \uniqid()]);
        $this->assertSame(201, $ios['headers']['status-code'], \json_encode($ios['body']));

        $head = $this->pushGiteaCommit($repository['name'], 'lib/main.dart', "void main() {}\n", 'Add entrypoint');

        /**
         * Test for SUCCESS
         */
        $builds = \array_column($this->awaitVcsBuilds($appId, 2, [Query::equal('providerCommitHash', [$head])->toString()]), null, 'platform');
        $this->assertSame($linked['targetId'], $builds['android']['targetId'] ?? '', 'A push builds every target of the app once.');
        $this->assertSame($ios['body']['$id'], $builds['ios']['targetId'] ?? '', 'A push builds every target of the app once.');

        foreach ($builds as $build) {
            $this->assertSame('vcs', $build['type']);
            $this->assertSame(self::GITEA_BRANCH, $build['providerBranch']);
            $this->assertSame('', $build['providerTag']);
            $this->assertStringStartsWith('Add entrypoint', $build['providerCommitMessage']);
            $this->assertSame(self::GITEA_USERNAME, $build['providerRepositoryOwner']);
            $this->assertSame($repository['name'], $build['providerRepositoryName']);
        }

        $this->assertSame(1, $builds['android']['versionCode'], 'An automatic target allocates the next version code.');
        $this->assertSame('1', $builds['ios']['buildNumber'], 'An automatic iOS target allocates the next build number.');

        $ready = $this->awaitBuild($appId, $builds['android']['$id'], ['ready']);
        $this->assertNotEmpty($ready['artifactId']);

        /**
         * Test for FAILURE
         */
        $failed = $this->awaitBuild($appId, $builds['ios']['$id'], ['failed']);
        $this->assertSame('distribution_runner_unavailable', $failed['errorCode'], 'An iOS build fails while no macOS runner exists.');
        $this->assertSame('', $failed['artifactId']);

        $this->assertSame(0, $this->listBuilds($appId, [Query::equal('type', ['manual'])->toString()])['body']['total']);
    }

    public function testTriggerBuildsOnlyTheProductionBranchByDefault(): void
    {
        $repository = $this->prepareRepository();
        $appId = $this->linkApp($repository)['appId'];
        $branch = 'feature/x-' . \uniqid();
        $this->createGiteaBranch($repository['name'], $branch);
        $this->pushGiteaCommit($repository['name'], 'lib/feature.dart', "// first\n", 'Start feature', $branch);

        /**
         * Test for FAILURE
         */
        $this->assertStays(
            fn (): int => $this->countVcsBuilds($appId, [Query::equal('providerBranch', [$branch])->toString()]),
            0,
            'An empty providerBranches builds only the production branch',
            self::QUIET,
        );

        /**
         * Test for SUCCESS
         */
        $updated = $this->updateApp($appId, ['providerBranches' => ['feature/*']]);
        $this->assertSame(200, $updated['headers']['status-code'], \json_encode($updated['body']));
        $this->assertSame(['feature/*'], $updated['body']['providerBranches']);

        $production = $this->pushGiteaCommit($repository['name'], 'lib/main.dart', "void main() {}\n", 'Production change');
        $head = $this->pushGiteaCommit($repository['name'], 'lib/feature.dart', "// second\n", 'Continue feature', $branch);

        $builds = $this->awaitVcsBuilds($appId, 1, [Query::equal('providerCommitHash', [$head])->toString()]);
        $this->assertSame($branch, $builds[0]['providerBranch']);

        /**
         * Test for FAILURE
         */
        $this->assertStays(
            fn (): array => [
                'branch' => $this->countVcsBuilds($appId, [Query::equal('providerBranch', [$branch])->toString()]),
                'production' => $this->countVcsBuilds($appId, [Query::equal('providerCommitHash', [$production])->toString()]),
            ],
            ['branch' => 1, 'production' => 0],
            'A non-empty providerBranches does not build the production branch it does not match',
            self::QUIET,
        );
    }

    public function testTriggerBuildsTags(): void
    {
        $repository = $this->prepareRepository();
        $linked = $this->linkApp($repository, ['providerTags' => ['v*']]);
        $appId = $linked['appId'];
        $head = $this->branchHead($repository['name']);
        $first = $this->firstCommit($repository['name']);
        $tag = 'v1.0.' . \random_int(0, 99999);

        $this->createGiteaTag($repository['name'], $tag);

        /**
         * Test for SUCCESS
         */
        $builds = $this->awaitVcsBuilds($appId, 1, $this->tagged($tag));
        $this->assertSame($tag, $builds[0]['providerTag']);
        $this->assertSame('', $builds[0]['providerBranch']);
        $this->assertSame($head, $builds[0]['providerCommitHash'], 'A tag builds the commit it points at.');
        $this->assertSame($linked['targetId'], $builds[0]['targetId']);

        $ready = $this->awaitBuild($appId, $builds[0]['$id'], ['ready']);
        $this->assertNotEmpty($ready['artifactId']);

        /**
         * Test for FAILURE
         */
        $nightly = 'nightly-' . \uniqid();
        $event = $this->sendGiteaPush($repository, self::TAGS . $nightly, $head);
        $this->assertSame(200, $event['headers']['status-code'], \json_encode($event['body']));
        $this->assertSame(0, $this->countVcsBuilds($appId, $this->tagged($nightly)), 'A tag that matches no providerTags pattern builds nothing.');

        $forged = $this->sendGiteaPush($repository, self::TAGS . $tag, $first);
        $this->assertSame(200, $forged['headers']['status-code'], \json_encode($forged['body']));
        $this->assertSame(1, $this->countVcsBuilds($appId, $this->tagged($tag)), 'A tag push naming a commit other than the tag commit builds nothing.');

        $missing = 'v9.9.' . \random_int(0, 99999);
        $event = $this->sendGiteaPush($repository, self::TAGS . $missing, $head);
        $this->assertSame(200, $event['headers']['status-code'], \json_encode($event['body']));
        $this->assertSame(0, $this->countVcsBuilds($appId, $this->tagged($missing)), 'A tag the provider does not have builds nothing.');

        $updated = $this->updateApp($appId, ['providerTags' => []]);
        $this->assertSame(200, $updated['headers']['status-code'], \json_encode($updated['body']));
        $this->assertSame([], $updated['body']['providerTags']);
        $untracked = 'v2.0.' . \random_int(0, 99999);
        $this->createGiteaTag($repository['name'], $untracked);

        $this->assertStays(
            fn (): int => $this->countVcsBuilds($appId, $this->tagged($untracked)),
            0,
            'An empty providerTags builds no tags',
            self::QUIET,
        );
    }

    public function testTriggerSkips(): void
    {
        $repository = $this->prepareRepository();
        $head = $this->branchHead($repository['name']);
        $apps = [
            'deployments' => $this->linkApp($repository, ['providerDeployments' => false])['appId'],
            'disabled' => $this->linkApp($repository, ['enabled' => false])['appId'],
            'paths' => $this->linkApp($repository, ['providerPaths' => ['lib/**']])['appId'],
            'manual' => $this->linkApp($repository, [], ['versionStrategy' => 'manual'])['appId'],
            'control' => $this->linkApp($repository)['appId'],
        ];
        $deliveries = [
            'skip pattern' => ['head_commit' => ['message' => 'Update docs [skip ci]']],
            'own commit' => ['head_commit' => ['author' => ['email' => APP_VCS_GITEA_EMAIL]]],
            'deleted branch' => ['deleted' => true],
        ];

        /**
         * Test for FAILURE
         */
        foreach ($deliveries as $case => $overrides) {
            $event = $this->sendGiteaPush($repository, self::HEADS . self::GITEA_BRANCH, $head, $overrides);
            $this->assertSame(200, $event['headers']['status-code'], $case . ': ' . \json_encode($event['body']));

            foreach ($apps as $name => $appId) {
                $this->assertSame(0, $this->countVcsBuilds($appId), "A push skipped for its {$case} builds nothing for the {$name} app.");
            }
        }

        /**
         * Test for SUCCESS
         */
        $event = $this->sendGiteaPush($repository, self::HEADS . self::GITEA_BRANCH, $head);
        $this->assertSame(200, $event['headers']['status-code'], \json_encode($event['body']));

        $builds = $this->vcsBuilds($apps['control']);
        $this->assertCount(1, $builds);
        $this->assertSame($head, $builds[0]['providerCommitHash'], 'The same delivery builds an app that nothing skips.');

        /**
         * Test for FAILURE
         */
        $this->assertSame(
            ['deployments' => 0, 'disabled' => 0, 'paths' => 0, 'manual' => 0, 'control' => 1],
            \array_map(fn (string $appId): int => $this->countVcsBuilds($appId), $apps),
            'Only the app that nothing skips builds.',
        );
    }

    public function testTriggerReadsTheCommitMessageFromTheProvider(): void
    {
        $repository = $this->prepareRepository();
        $appId = $this->linkApp($repository)['appId'];
        $skipped = $this->pushGiteaCommit($repository['name'], 'CHANGELOG.md', "# Changes\n", 'Update changelog [skip ci]');

        /**
         * Test for FAILURE
         */
        $forged = $this->sendGiteaPush($repository, self::HEADS . self::GITEA_BRANCH, $skipped, [
            'head_commit' => ['message' => 'Synthetic push without a skip pattern'],
        ]);
        $this->assertSame(200, $forged['headers']['status-code'], \json_encode($forged['body']));
        $this->assertSame(0, $this->countVcsBuilds($appId, [Query::equal('providerCommitHash', [$skipped])->toString()]), 'A head whose own message carries a skip pattern builds nothing, whatever message the delivery claims.');

        /**
         * Test for SUCCESS
         */
        $head = $this->pushGiteaCommit($repository['name'], 'lib/main.dart', "void main() {}\n", 'Ship the changelog');

        $builds = $this->awaitVcsBuilds($appId, 1, [Query::equal('providerCommitHash', [$head])->toString()]);
        $this->assertStringStartsWith('Ship the changelog', $builds[0]['providerCommitMessage'], 'The build records the message the provider reports.');
    }

    public function testTriggerIsIdempotent(): void
    {
        $repository = $this->prepareRepository();
        $head = $this->branchHead($repository['name']);
        $linked = $this->linkApp($repository);
        $appId = $linked['appId'];
        $ios = $this->createTarget($appId, ['platform' => 'ios', 'identifier' => 'io.appwrite.trigger' . \uniqid()]);
        $this->assertSame(201, $ios['headers']['status-code'], \json_encode($ios['body']));
        $targetIds = [$linked['targetId'], $ios['body']['$id']];

        foreach ($targetIds as $targetId) {
            $this->assertSame(0, $this->getTarget($appId, $targetId)['body']['versionCode']);
        }

        /**
         * Test for SUCCESS
         */
        foreach ([1, 2] as $delivery) {
            $event = $this->sendGiteaPush($repository, self::HEADS . self::GITEA_BRANCH, $head);
            $this->assertSame(200, $event['headers']['status-code'], "Delivery {$delivery}: " . \json_encode($event['body']));
        }

        $builds = $this->vcsBuilds($appId);
        $this->assertCount(2, $builds, 'A redelivery creates no second build.');
        $this->assertEqualsCanonicalizing($targetIds, \array_column($builds, 'targetId'), 'A redelivered push builds each target once.');

        foreach ($targetIds as $targetId) {
            $target = $this->getTarget($appId, $targetId);
            $this->assertSame(200, $target['headers']['status-code']);
            $this->assertSame(1, $target['body']['versionCode'], 'A redelivery allocates no second version code.');
        }
    }

    public function testTriggerRefusesAStaleHead(): void
    {
        $repository = $this->prepareRepository();
        $head = $this->branchHead($repository['name']);
        $first = $this->firstCommit($repository['name']);
        $this->assertNotSame($head, $first);
        $appId = $this->linkApp($repository)['appId'];

        /**
         * Test for FAILURE
         */
        $stale = $this->sendGiteaPush($repository, self::HEADS . self::GITEA_BRANCH, $first);
        $this->assertSame(200, $stale['headers']['status-code'], \json_encode($stale['body']));
        $this->assertSame(0, $this->countVcsBuilds($appId, [Query::equal('providerCommitHash', [$first])->toString()]), 'A push naming a commit the branch does not point at builds nothing.');

        /**
         * Test for SUCCESS
         */
        $current = $this->sendGiteaPush($repository, self::HEADS . self::GITEA_BRANCH, $head);
        $this->assertSame(200, $current['headers']['status-code'], \json_encode($current['body']));

        $builds = $this->vcsBuilds($appId);
        $this->assertCount(1, $builds);
        $this->assertSame($head, $builds[0]['providerCommitHash']);
    }

    public function testTriggerIgnoresOtherProviders(): void
    {
        $repository = $this->prepareRepository();
        $appId = $this->linkApp($repository)['appId'];
        $commitHash = $this->branchHead($repository['name']);
        $url = 'https://github.com/' . self::GITEA_USERNAME . '/' . $repository['name'];
        $payload = [
            'created' => false,
            'deleted' => false,
            'ref' => self::HEADS . self::GITEA_BRANCH,
            'before' => $commitHash,
            'after' => $commitHash,
            'repository' => [
                'id' => (int) $repository['id'],
                'name' => $repository['name'],
                'full_name' => self::GITEA_USERNAME . '/' . $repository['name'],
                'private' => false,
                'html_url' => $url,
                'owner' => ['name' => self::GITEA_USERNAME],
            ],
            'installation' => ['id' => 1],
            'head_commit' => [
                'author' => ['name' => 'Provider Filter', 'email' => 'distribution-e2e@appwrite.io'],
                'message' => 'Provider filter',
                'url' => $url . '/commit/' . $commitHash,
            ],
            'commits' => [[
                'id' => $commitHash,
                'added' => [],
                'removed' => [],
                'modified' => ['README.md'],
            ]],
            'sender' => ['html_url' => 'https://github.com/' . self::GITEA_USERNAME, 'avatar_url' => ''],
        ];

        /**
         * Test for FAILURE
         */
        $event = $this->client->call(Client::METHOD_POST, '/vcs/github/events', [
            'content-type' => 'application/json',
            'x-github-event' => 'push',
            'x-hub-signature-256' => 'sha256=' . \hash_hmac('sha256', \json_encode($payload, JSON_THROW_ON_ERROR), System::getEnv('_APP_VCS_GITHUB_WEBHOOK_SECRET', '')),
        ], $payload);
        $this->assertSame(200, $event['headers']['status-code'], \json_encode($event['body']));
        $this->assertSame(0, $this->countVcsBuilds($appId), 'A GitHub delivery naming a Gitea repository id builds nothing.');
    }

    public function testTriggerSharesTheRepository(): void
    {
        $repository = $this->prepareRepository();
        $link = [
            'installationId' => $this->createGiteaInstallation()['$id'],
            'providerRepositoryId' => (string) $repository['id'],
            'providerBranch' => self::GITEA_BRANCH,
        ];

        $function = $this->client->call(Client::METHOD_POST, '/functions', $this->headers(), [
            'functionId' => ID::unique(),
            'name' => 'Trigger ' . \uniqid(),
            'runtime' => 'node-22',
            'entrypoint' => 'index.js',
            ...$link,
        ]);
        $this->assertSame(201, $function['headers']['status-code'], \json_encode($function['body']));
        $functionId = $function['body']['$id'];

        $site = $this->client->call(Client::METHOD_POST, '/sites', $this->headers(), [
            'siteId' => ID::unique(),
            'name' => 'Trigger ' . \uniqid(),
            'framework' => 'other',
            'buildRuntime' => 'node-22',
            ...$link,
        ]);
        $this->assertSame(201, $site['headers']['status-code'], \json_encode($site['body']));
        $siteId = $site['body']['$id'];

        $appId = $this->linkApp($repository)['appId'];

        /**
         * Test for SUCCESS
         */
        $this->assertCount(1, $this->listGiteaHooks($repository['name']), 'A function, a site and an app share one webhook.');

        $installations = $this->client->call(Client::METHOD_GET, '/vcs/installations', [
            'x-appwrite-project' => $this->getProject()['$id'],
            'origin' => 'http://localhost',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-mode' => 'admin',
        ]);
        $this->assertSame(200, $installations['headers']['status-code']);
        $gitea = \array_filter($installations['body']['installations'], fn (array $installation): bool => $installation['provider'] === 'gitea');
        $this->assertCount(1, $gitea, 'No second installation is created.');

        $head = $this->pushGiteaCommit($repository['name'], 'index.js', "module.exports = async (context) => context.res.send('shared');\n", 'Shared change');

        $this->assertEventually(function () use ($functionId, $head): void {
            $this->assertContains($head, \array_column($this->listDeployments('functions', $functionId), 'providerCommitHash'), 'The push deploys the function.');
        }, self::DELIVERY, 2000);

        $this->assertEventually(function () use ($siteId, $head): void {
            $this->assertContains($head, \array_column($this->listDeployments('sites', $siteId), 'providerCommitHash'), 'The push deploys the site.');
        }, self::DELIVERY, 2000);

        $builds = $this->awaitVcsBuilds($appId, 1, [Query::equal('providerCommitHash', [$head])->toString()]);
        $this->assertSame(self::GITEA_BRANCH, $builds[0]['providerBranch'], 'The push builds the app.');
    }

    public function testTriggerSubmitsToInternal(): void
    {
        $serviceAccount = $this->seedGoogleServiceAccount();
        $connection = $this->createGoogleConnection(['serviceAccount' => $serviceAccount]);
        $this->assertSame(201, $connection['headers']['status-code'], \json_encode($connection['body']));
        $keystore = Keystore::generate('upload', ['CN' => 'Appwrite Triggers']);
        $identity = $this->createKeystoreIdentity($keystore);
        $this->assertSame(201, $identity['headers']['status-code'], \json_encode($identity['body']));

        $repository = $this->prepareRepository();
        $identifier = 'io.appwrite.trigger' . \uniqid();
        $linked = $this->linkApp($repository, ['framework' => 'android'], [
            'identifier' => $identifier,
            'connectionId' => $connection['body']['$id'],
            'signingIdentityId' => $identity['body']['$id'],
            'track' => 'internal',
            'automatic' => true,
        ]);
        $appId = $linked['appId'];
        $this->seedGoogleApplication($identifier, $serviceAccount['client_email']);
        $uploads = $this->googleUploads($identifier)['completed'];

        $head = $this->pushGiteaCommit($repository['name'], 'lib/main.dart', "void main() {}\n", 'Ship it');

        /**
         * Test for SUCCESS
         */
        $build = $this->awaitBuild($appId, $this->awaitVcsBuilds($appId, 1, [Query::equal('providerCommitHash', [$head])->toString()])[0]['$id'], ['ready']);
        $artifact = $this->client->call(Client::METHOD_GET, '/distribution/apps/' . $appId . '/artifacts/' . $build['artifactId'], $this->headers());
        $this->assertSame(200, $artifact['headers']['status-code']);
        $this->assertTrue($artifact['body']['signed']);
        $this->assertSame($keystore->certificate->fingerprint(), $artifact['body']['fingerprint']);

        $submissions = [];
        $this->assertEventually(function () use ($appId, $build, &$submissions): void {
            $listed = $this->listSubmissions($appId, [Query::equal('artifactId', [$build['artifactId']])->toString()]);
            $this->assertSame(200, $listed['headers']['status-code']);
            $this->assertSame(1, $listed['body']['total'], 'A ready build of an automatic target is submitted once.');
            $submissions = $listed['body']['submissions'];
        }, self::DELIVERY, 2000);

        $this->assertSame('internal', $submissions[0]['track']);
        $this->assertSame($linked['targetId'], $submissions[0]['targetId']);

        $published = $this->awaitSubmission($appId, $submissions[0]['$id'], 'published');
        $this->assertSame('internal', $published['track']);

        $release = $this->getRelease($appId, $published['releaseId']);
        $this->assertSame(200, $release['headers']['status-code']);
        $this->assertSame($artifact['body']['versionName'] ?: (string) $artifact['body']['versionCode'], $release['body']['version'], 'The release is named after the built version.');

        $this->assertSame($uploads + 1, $this->googleUploads($identifier)['completed'], 'The bundle is uploaded once.');

        /**
         * Test for FAILURE
         */
        $this->assertStays(
            fn (): int => $this->listSubmissions($appId)['body']['total'],
            1,
            'Settling the build again submits nothing more',
            self::RECONCILE,
        );
    }

    public function testTriggerDoesNotSubmitUnsignedArtifacts(): void
    {
        $serviceAccount = $this->seedGoogleServiceAccount();
        $connection = $this->createGoogleConnection(['serviceAccount' => $serviceAccount]);
        $this->assertSame(201, $connection['headers']['status-code'], \json_encode($connection['body']));

        $repository = $this->prepareRepository();
        $identifier = 'io.appwrite.trigger' . \uniqid();
        $appId = $this->linkApp($repository, ['framework' => 'android'], [
            'identifier' => $identifier,
            'connectionId' => $connection['body']['$id'],
            'track' => 'internal',
            'automatic' => true,
        ])['appId'];
        $this->seedGoogleApplication($identifier, $serviceAccount['client_email']);
        $uploads = $this->googleUploads($identifier)['completed'];

        $head = $this->pushGiteaCommit($repository['name'], 'lib/main.dart', "void main() {}\n", 'Ship unsigned');

        $build = $this->awaitBuild($appId, $this->awaitVcsBuilds($appId, 1, [Query::equal('providerCommitHash', [$head])->toString()])[0]['$id'], ['ready']);
        $artifact = $this->client->call(Client::METHOD_GET, '/distribution/apps/' . $appId . '/artifacts/' . $build['artifactId'], $this->headers());
        $this->assertSame(200, $artifact['headers']['status-code']);
        $this->assertFalse($artifact['body']['signed']);

        /**
         * Test for FAILURE
         */
        $this->assertStays(
            fn (): int => $this->listSubmissions($appId)['body']['total'],
            0,
            'An unsigned artifact is never submitted',
            self::QUIET,
        );

        $releases = $this->client->call(Client::METHOD_GET, '/distribution/apps/' . $appId . '/releases', $this->headers());
        $this->assertSame(200, $releases['headers']['status-code']);
        $this->assertSame(0, $releases['body']['total'], 'A refused automatic submission creates no release.');
        $this->assertSame($uploads, $this->googleUploads($identifier)['completed'], 'Nothing reaches the store.');
    }

    public function testTriggerEndToEnd(): void
    {
        $serviceAccount = $this->seedGoogleServiceAccount();
        $connection = $this->createGoogleConnection(['serviceAccount' => $serviceAccount]);
        $this->assertSame(201, $connection['headers']['status-code'], \json_encode($connection['body']));

        $identity = $this->client->call(Client::METHOD_POST, '/distribution/signing-identities/android', $this->headers(), [
            'signingIdentityId' => ID::unique(),
            'name' => 'Managed ' . \uniqid(),
        ]);
        $this->assertSame(201, $identity['headers']['status-code'], \json_encode($identity['body']));
        $this->assertSame('managed', $identity['body']['mode']);
        $this->assertNotEmpty($identity['body']['fingerprint']);

        $repository = $this->prepareRepository();
        $identifier = 'io.appwrite.trigger' . \uniqid();
        $linked = $this->linkApp($repository, ['framework' => 'android'], [
            'identifier' => $identifier,
            'connectionId' => $connection['body']['$id'],
            'signingIdentityId' => $identity['body']['$id'],
            'track' => 'internal',
            'automatic' => true,
        ]);
        $appId = $linked['appId'];
        $this->seedGoogleApplication($identifier, $serviceAccount['client_email']);
        $before = $this->googleUploads($identifier);

        $this->pushGiteaCommit($repository['name'], 'lib/main.dart', "void main() {}\n", 'Release it');

        /**
         * Test for SUCCESS
         */
        $build = $this->awaitBuild($appId, $this->awaitVcsBuilds($appId, 1)[0]['$id'], ['ready']);
        $artifact = $this->client->call(Client::METHOD_GET, '/distribution/apps/' . $appId . '/artifacts/' . $build['artifactId'], $this->headers());
        $this->assertSame(200, $artifact['headers']['status-code']);
        $this->assertTrue($artifact['body']['signed']);
        $this->assertSame($identity['body']['fingerprint'], $artifact['body']['fingerprint'], 'The unsigned bundle is signed with the managed upload key.');

        $submissions = [];
        $this->assertEventually(function () use ($appId, $build, &$submissions): void {
            $listed = $this->listSubmissions($appId, [Query::equal('artifactId', [$build['artifactId']])->toString()]);
            $this->assertSame(200, $listed['headers']['status-code']);
            $this->assertSame(1, $listed['body']['total']);
            $submissions = $listed['body']['submissions'];
        }, self::DELIVERY, 2000);

        $internal = $this->awaitSubmission($appId, $submissions[0]['$id'], 'published');
        $this->assertSame('internal', $internal['track']);
        $this->assertSame('settled', $internal['step']);
        $uploaded = $this->googleUploads($identifier);
        $this->assertSame($before['completed'] + 1, $uploaded['completed'], 'The internal submission uploads the bundle.');

        $promoted = $this->createSubmission($appId, [
            'releaseId' => $internal['releaseId'],
            'targetId' => $linked['targetId'],
            'artifactId' => $build['artifactId'],
            'track' => 'production',
            'rollout' => self::FRACTION,
        ]);
        $this->assertSame(201, $promoted['headers']['status-code'], \json_encode($promoted['body']));
        $promotionId = $promoted['body']['$id'];

        $production = $this->awaitSubmission($appId, $promotionId, 'published');
        $this->assertSame('production', $production['track']);
        $this->assertSame(self::FRACTION, (float) $production['rollout']);
        $this->assertSame('released', $production['step']);
        $this->assertSame($uploaded, $this->googleUploads($identifier), 'The promotion to production repeats no upload.');

        $full = $this->updateSubmission($appId, $promotionId, ['rollout' => 1]);
        $this->assertSame(200, $full['headers']['status-code'], \json_encode($full['body']));

        $this->assertEventually(function () use ($appId, $promotionId): void {
            $submission = $this->getSubmission($appId, $promotionId)['body'];
            $this->assertSame('published', $submission['status']);
            $this->assertEqualsWithDelta(1.0, (float) $submission['rollout'], PHP_FLOAT_EPSILON);
            $this->assertSame('settled', $submission['step']);
        }, self::DELIVERY * 2, 2000);

        /**
         * Test for FAILURE
         */
        $again = $this->createSubmission($appId, [
            'releaseId' => $internal['releaseId'],
            'targetId' => $linked['targetId'],
            'artifactId' => $build['artifactId'],
            'track' => 'production',
            'key' => 'e2e-' . \uniqid(),
        ]);
        $this->assertSame(400, $again['headers']['status-code']);
        $this->assertSame('distribution_version_invalid', $again['body']['type']);

        $rolledOut = $this->updateSubmission($appId, $promotionId, ['rollout' => self::FRACTION]);
        $this->assertSame(409, $rolledOut['headers']['status-code']);
        $this->assertSame('distribution_submission_transition_invalid', $rolledOut['body']['type']);

        $this->assertSame(2, $this->listSubmissions($appId)['body']['total']);
        $this->assertSame($uploaded, $this->googleUploads($identifier));
    }

    /**
     * @return array<string, mixed> The Gitea repository
     */
    private function prepareRepository(): array
    {
        $this->createGiteaInstallation();

        return $this->createGiteaRepository(['pubspec.yaml' => self::SOURCES]);
    }

    /**
     * @param array<string, mixed> $repository The Gitea repository
     * @param array<string, mixed> $app
     * @param array<string, mixed> $target
     * @return array{appId: string, targetId: string}
     */
    private function linkApp(array $repository, array $app = [], array $target = []): array
    {
        $created = $this->createApp([
            'installationId' => $this->createGiteaInstallation()['$id'],
            'providerRepositoryId' => (string) $repository['id'],
            'providerBranch' => self::GITEA_BRANCH,
            ...$app,
        ]);
        $this->assertSame(201, $created['headers']['status-code'], \json_encode($created['body']));

        $targeted = $this->createTarget($created['body']['$id'], [
            'identifier' => 'io.appwrite.trigger' . \uniqid(),
            ...$target,
        ]);
        $this->assertSame(201, $targeted['headers']['status-code'], \json_encode($targeted['body']));

        return [
            'appId' => $created['body']['$id'],
            'targetId' => $targeted['body']['$id'],
        ];
    }

    private function firstCommit(string $repository): string
    {
        $commits = $this->giteaApi(Client::METHOD_GET, '/api/v1/repos/' . self::GITEA_USERNAME . '/' . $repository . '/commits', [
            'sha' => self::GITEA_BRANCH,
        ]);
        $this->assertSame(200, $commits['headers']['status-code'], \json_encode($commits['body']));
        $this->assertGreaterThan(1, \count($commits['body']));

        return \end($commits['body'])['sha'];
    }

    /**
     * @param list<string> $queries
     * @return list<array<string, mixed>>
     */
    private function vcsBuilds(string $appId, array $queries = []): array
    {
        $listed = $this->listBuilds($appId, [
            Query::equal('type', ['vcs'])->toString(),
            Query::limit(100)->toString(),
            ...$queries,
        ]);
        $this->assertSame(200, $listed['headers']['status-code'], \json_encode($listed['body']));

        return $listed['body']['builds'];
    }

    /**
     * @param list<string> $queries
     */
    private function countVcsBuilds(string $appId, array $queries = []): int
    {
        return \count($this->vcsBuilds($appId, $queries));
    }

    /**
     * @param list<string> $queries
     * @return list<array<string, mixed>>
     */
    private function awaitVcsBuilds(string $appId, int $count, array $queries = []): array
    {
        $builds = [];

        $this->assertEventually(function () use ($appId, $count, $queries, &$builds): void {
            $builds = $this->vcsBuilds($appId, $queries);
            $this->assertCount($count, $builds);
        }, self::DELIVERY, 1000);

        return $builds;
    }

    /**
     * @return list<string>
     */
    private function tagged(string $tag): array
    {
        return [Query::equal('providerTag', [$tag])->toString()];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function updateApp(string $appId, array $params): array
    {
        return $this->client->call(Client::METHOD_PATCH, '/distribution/apps/' . $appId, $this->headers(), $params);
    }

    /**
     * @return array<string, mixed>
     */
    private function getTarget(string $appId, string $targetId): array
    {
        return $this->client->call(Client::METHOD_GET, '/distribution/apps/' . $appId . '/targets/' . $targetId, $this->headers());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listDeployments(string $resource, string $resourceId): array
    {
        $deployments = $this->client->call(Client::METHOD_GET, '/' . $resource . '/' . $resourceId . '/deployments', $this->headers());
        $this->assertSame(200, $deployments['headers']['status-code'], \json_encode($deployments['body']));

        return $deployments['body']['deployments'];
    }
}
