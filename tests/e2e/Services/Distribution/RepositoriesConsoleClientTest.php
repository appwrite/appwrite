<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Distribution;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideConsole;
use Tests\E2E\Services\VCSGitea\VCSGiteaBase;

final class RepositoriesConsoleClientTest extends Scope
{
    use DistributionBase;
    use GiteaRepositories;
    use VCSGiteaBase;
    use ProjectCustom;
    use SideConsole;

    private const string PUBSPEC = "name: fixture\nenvironment:\n  sdk: '>=3.0.0 <4.0.0'\ndependencies:\n  flutter:\n    sdk: flutter\n";

    public function testCreateAppLinksRepository(): void
    {
        $installationId = $this->createGiteaInstallation()['$id'];
        $repository = $this->createGiteaRepository(['pubspec.yaml' => self::PUBSPEC]);

        /**
         * Test for SUCCESS
         */
        $created = $this->createApp([
            'installationId' => $installationId,
            'providerRepositoryId' => (string) $repository['id'],
            'providerBranch' => 'main',
            'providerRootDirectory' => 'mobile',
            'providerBranches' => ['main', 'release/*'],
            'providerPaths' => ['lib/**'],
            'providerTags' => ['v*'],
            'providerSilentMode' => true,
            'providerComments' => false,
            'providerDeployments' => false,
            'providerStatuses' => false,
        ]);
        $this->assertSame(201, $created['headers']['status-code'], \json_encode($created['body']));
        $this->assertSame($installationId, $created['body']['installationId']);
        $this->assertSame((string) $repository['id'], $created['body']['providerRepositoryId']);
        $this->assertNotEmpty($created['body']['repositoryId']);
        $this->assertSame('main', $created['body']['providerBranch']);
        $this->assertSame('mobile', $created['body']['providerRootDirectory']);
        $this->assertSame(['main', 'release/*'], $created['body']['providerBranches']);
        $this->assertSame(['lib/**'], $created['body']['providerPaths']);
        $this->assertSame(['v*'], $created['body']['providerTags']);
        $this->assertTrue($created['body']['providerSilentMode']);
        $this->assertFalse($created['body']['providerComments']);
        $this->assertFalse($created['body']['providerDeployments']);
        $this->assertFalse($created['body']['providerStatuses']);

        $fetched = $this->client->call(Client::METHOD_GET, '/distribution/apps/' . $created['body']['$id'], $this->headers());
        $this->assertSame(200, $fetched['headers']['status-code']);
        $this->assertSame($created['body']['repositoryId'], $fetched['body']['repositoryId']);
        $this->assertSame((string) $repository['id'], $fetched['body']['providerRepositoryId']);

        $this->assertCount(1, $this->listGiteaHooks($repository['name']), 'Linking the repository must register one push webhook in Gitea.');

        $defaults = $this->createApp([
            'installationId' => $installationId,
            'providerRepositoryId' => (string) $repository['id'],
            'providerBranch' => 'main',
        ]);
        $this->assertSame(201, $defaults['headers']['status-code'], \json_encode($defaults['body']));
        $this->assertNotSame($created['body']['repositoryId'], $defaults['body']['repositoryId']);
        $this->assertTrue($defaults['body']['providerComments']);
        $this->assertTrue($defaults['body']['providerDeployments']);
        $this->assertTrue($defaults['body']['providerStatuses']);
        $this->assertFalse($defaults['body']['providerSilentMode']);
        $this->assertCount(1, $this->listGiteaHooks($repository['name']), 'A second app on the same repository must reuse the webhook.');

        /**
         * Test for FAILURE
         */
        $branchless = $this->createApp([
            'installationId' => $installationId,
            'providerRepositoryId' => (string) $repository['id'],
        ]);
        $this->assertSame(400, $branchless['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $branchless['body']['type']);

        $appId = 'missing-' . \uniqid();
        $missing = $this->createApp([
            'appId' => $appId,
            'installationId' => $installationId,
            'providerRepositoryId' => '999999999',
            'providerBranch' => 'main',
        ]);
        $this->assertSame(404, $missing['headers']['status-code']);
        $this->assertSame('provider_repository_not_found', $missing['body']['type']);
        $this->assertSame(404, $this->client->call(Client::METHOD_GET, '/distribution/apps/' . $appId, $this->headers())['headers']['status-code'], 'A refused link must not leave the app behind.');
    }

    public function testCreateAppRejectsForeignInstallation(): void
    {
        $foreign = $this->createGiteaInstallation($this->getProject(true)['$id']);
        $repository = $this->createGiteaRepository();

        /**
         * Test for FAILURE
         */
        $created = $this->createApp([
            'installationId' => $foreign['$id'],
            'providerRepositoryId' => (string) $repository['id'],
            'providerBranch' => 'main',
        ]);
        $this->assertSame(404, $created['headers']['status-code']);
        $this->assertSame('installation_not_found', $created['body']['type']);

        $app = $this->createApp();
        $this->assertSame(201, $app['headers']['status-code']);

        $updated = $this->updateLink($app['body']['$id'], [
            'installationId' => $foreign['$id'],
            'providerRepositoryId' => (string) $repository['id'],
            'providerBranch' => 'main',
        ]);
        $this->assertSame(404, $updated['headers']['status-code']);
        $this->assertSame('installation_not_found', $updated['body']['type']);
        $this->assertCount(0, $this->listGiteaHooks($repository['name']));
    }

    public function testUpdateAppConnectsRepository(): void
    {
        $installationId = $this->createGiteaInstallation()['$id'];
        $repository = $this->createGiteaRepository();
        $app = $this->createApp();
        $this->assertSame(201, $app['headers']['status-code']);

        /**
         * Test for SUCCESS
         */
        $connected = $this->updateLink($app['body']['$id'], [
            'installationId' => $installationId,
            'providerRepositoryId' => (string) $repository['id'],
            'providerBranch' => 'main',
        ]);
        $this->assertSame(200, $connected['headers']['status-code'], \json_encode($connected['body']));
        $this->assertSame($installationId, $connected['body']['installationId']);
        $this->assertSame((string) $repository['id'], $connected['body']['providerRepositoryId']);
        $this->assertSame('main', $connected['body']['providerBranch']);
        $this->assertNotEmpty($connected['body']['repositoryId']);
        $this->assertCount(1, $this->listGiteaHooks($repository['name']));

        $renamed = $this->updateLink($app['body']['$id'], ['name' => 'Renamed ' . \uniqid()]);
        $this->assertSame(200, $renamed['headers']['status-code']);
        $this->assertSame($connected['body']['repositoryId'], $renamed['body']['repositoryId'], 'An unrelated update must keep the repository.');

        /**
         * Test for FAILURE
         */
        $moved = $this->updateLink($app['body']['$id'], ['installationId' => 'other-' . \uniqid()]);
        $this->assertSame(400, $moved['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $moved['body']['type']);

        $branchless = $this->updateLink($app['body']['$id'], [
            'installationId' => $installationId,
            'providerRepositoryId' => (string) $repository['id'],
        ]);
        $this->assertSame(400, $branchless['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $branchless['body']['type']);

        $this->assertSame($connected['body']['repositoryId'], $this->client->call(Client::METHOD_GET, '/distribution/apps/' . $app['body']['$id'], $this->headers())['body']['repositoryId']);
    }

    public function testUpdateAppDisconnectsRepository(): void
    {
        $installationId = $this->createGiteaInstallation()['$id'];
        $repository = $this->createGiteaRepository();
        $link = [
            'installationId' => $installationId,
            'providerRepositoryId' => (string) $repository['id'],
            'providerBranch' => 'main',
        ];
        $app = $this->createApp($link + [
            'providerRootDirectory' => 'mobile',
            'providerBranches' => ['main'],
            'providerPaths' => ['lib/**'],
            'providerTags' => ['v*'],
            'providerSilentMode' => true,
        ]);
        $this->assertSame(201, $app['headers']['status-code'], \json_encode($app['body']));
        $appId = $app['body']['$id'];

        /**
         * Test for SUCCESS
         */
        $disconnected = $this->updateLink($appId, ['providerRepositoryId' => '']);
        $this->assertSame(200, $disconnected['headers']['status-code'], \json_encode($disconnected['body']));
        $this->assertSame('', $disconnected['body']['installationId']);
        $this->assertSame('', $disconnected['body']['providerRepositoryId']);
        $this->assertSame('', $disconnected['body']['repositoryId']);
        $this->assertSame('', $disconnected['body']['providerBranch']);
        $this->assertSame('', $disconnected['body']['providerRootDirectory']);
        $this->assertSame([], $disconnected['body']['providerBranches']);
        $this->assertSame([], $disconnected['body']['providerPaths']);
        $this->assertSame([], $disconnected['body']['providerTags']);
        $this->assertFalse($disconnected['body']['providerSilentMode']);

        $again = $this->updateLink($appId, ['providerRepositoryId' => '']);
        $this->assertSame(200, $again['headers']['status-code'], 'Disconnecting a disconnected app is a no-op.');

        $reconnected = $this->updateLink($appId, $link);
        $this->assertSame(200, $reconnected['headers']['status-code'], \json_encode($reconnected['body']));
        $this->assertNotEmpty($reconnected['body']['repositoryId']);
        $this->assertNotSame($app['body']['repositoryId'], $reconnected['body']['repositoryId']);
        $this->assertSame((string) $repository['id'], $reconnected['body']['providerRepositoryId']);
    }

    public function testUpdateAppRelinksAnotherRepository(): void
    {
        $installationId = $this->createGiteaInstallation()['$id'];
        $first = $this->createGiteaRepository();
        $second = $this->createGiteaRepository();
        $app = $this->createApp([
            'installationId' => $installationId,
            'providerRepositoryId' => (string) $first['id'],
            'providerBranch' => 'main',
        ]);
        $this->assertSame(201, $app['headers']['status-code'], \json_encode($app['body']));

        /**
         * Test for SUCCESS
         */
        $relinked = $this->updateLink($app['body']['$id'], [
            'installationId' => $installationId,
            'providerRepositoryId' => (string) $second['id'],
            'providerBranch' => 'develop',
        ]);
        $this->assertSame(200, $relinked['headers']['status-code'], \json_encode($relinked['body']));
        $this->assertSame((string) $second['id'], $relinked['body']['providerRepositoryId']);
        $this->assertSame('develop', $relinked['body']['providerBranch']);
        $this->assertNotEmpty($relinked['body']['repositoryId']);
        $this->assertNotSame($app['body']['repositoryId'], $relinked['body']['repositoryId']);
        $this->assertCount(1, $this->listGiteaHooks($second['name']));

        $same = $this->updateLink($app['body']['$id'], [
            'installationId' => $installationId,
            'providerRepositoryId' => (string) $second['id'],
            'providerBranch' => 'main',
        ]);
        $this->assertSame(200, $same['headers']['status-code'], \json_encode($same['body']));
        $this->assertSame($relinked['body']['repositoryId'], $same['body']['repositoryId'], 'Relinking the same repository must keep its row.');
        $this->assertSame('main', $same['body']['providerBranch']);

        /**
         * Test for FAILURE
         */
        $missing = $this->updateLink($app['body']['$id'], [
            'installationId' => $installationId,
            'providerRepositoryId' => '999999999',
            'providerBranch' => 'main',
        ]);
        $this->assertSame(404, $missing['headers']['status-code']);
        $this->assertSame('provider_repository_not_found', $missing['body']['type']);
        $this->assertSame((string) $second['id'], $this->client->call(Client::METHOD_GET, '/distribution/apps/' . $app['body']['$id'], $this->headers())['body']['providerRepositoryId']);
    }

    public function testUpdateProviderFlags(): void
    {
        $repository = $this->createGiteaRepository();
        $app = $this->createApp([
            'installationId' => $this->createGiteaInstallation()['$id'],
            'providerRepositoryId' => (string) $repository['id'],
            'providerBranch' => 'main',
        ]);
        $this->assertSame(201, $app['headers']['status-code'], \json_encode($app['body']));

        /**
         * Test for SUCCESS
         */
        $updated = $this->updateLink($app['body']['$id'], [
            'providerSilentMode' => true,
            'providerComments' => false,
            'providerDeployments' => false,
            'providerStatuses' => false,
            'providerRootDirectory' => 'apps/mobile',
            'providerBranches' => ['release/*'],
            'providerPaths' => ['apps/mobile/**'],
            'providerTags' => ['v*'],
        ]);
        $this->assertSame(200, $updated['headers']['status-code'], \json_encode($updated['body']));
        $this->assertTrue($updated['body']['providerSilentMode']);
        $this->assertFalse($updated['body']['providerComments']);
        $this->assertFalse($updated['body']['providerDeployments']);
        $this->assertFalse($updated['body']['providerStatuses']);
        $this->assertSame('apps/mobile', $updated['body']['providerRootDirectory']);
        $this->assertSame(['release/*'], $updated['body']['providerBranches']);
        $this->assertSame(['apps/mobile/**'], $updated['body']['providerPaths']);
        $this->assertSame(['v*'], $updated['body']['providerTags']);
        $this->assertSame($app['body']['repositoryId'], $updated['body']['repositoryId']);

        $restored = $this->updateLink($app['body']['$id'], ['providerStatuses' => true]);
        $this->assertSame(200, $restored['headers']['status-code']);
        $this->assertTrue($restored['body']['providerStatuses']);
        $this->assertFalse($restored['body']['providerDeployments'], 'A sparse update must keep the other flags.');
        $this->assertTrue($restored['body']['providerSilentMode']);

        /**
         * Test for FAILURE
         */
        $directory = $this->updateLink($app['body']['$id'], ['providerRootDirectory' => '../outside']);
        $this->assertSame(400, $directory['headers']['status-code']);

        $branch = $this->updateLink($app['body']['$id'], ['providerBranch' => '']);
        $this->assertSame(400, $branch['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $branch['body']['type']);
        $this->assertSame('main', $this->client->call(Client::METHOD_GET, '/distribution/apps/' . $app['body']['$id'], $this->headers())['body']['providerBranch'], 'A connected app keeps its production branch.');
    }

    public function testDetectFlutter(): void
    {
        $repository = $this->createGiteaRepository([
            'pubspec.yaml' => self::PUBSPEC,
            'android/app/build.gradle' => "plugins {\n    id \"com.android.application\"\n}\n",
            'ios/Podfile' => "platform :ios, '13.0'\n",
            'mobile/pubspec.yaml' => self::PUBSPEC,
        ]);

        /**
         * Test for SUCCESS
         */
        $detection = $this->detect($repository);
        $this->assertSame(200, $detection['headers']['status-code'], \json_encode($detection['body']));
        $this->assertSame('distribution', $detection['body']['type']);
        $this->assertSame('flutter', $detection['body']['framework']);

        $nested = $this->detect($repository, 'mobile');
        $this->assertSame(200, $nested['headers']['status-code'], \json_encode($nested['body']));
        $this->assertSame('flutter', $nested['body']['framework']);
    }

    public function testDetectReactNative(): void
    {
        $repository = $this->createGiteaRepository([
            'package.json' => (string) \json_encode(['name' => 'fixture', 'dependencies' => ['react' => '18.3.1', 'react-native' => '0.76.0']]),
        ]);

        /**
         * Test for SUCCESS
         */
        $detection = $this->detect($repository);
        $this->assertSame(200, $detection['headers']['status-code'], \json_encode($detection['body']));
        $this->assertSame('react-native', $detection['body']['framework']);
    }

    public function testDetectNativeAndroid(): void
    {
        $repository = $this->createGiteaRepository([
            'settings.gradle.kts' => "rootProject.name = \"fixture\"\ninclude(\":app\")\n",
            'app/build.gradle.kts' => "plugins {\n    id(\"com.android.application\")\n}\n",
        ]);

        /**
         * Test for SUCCESS
         */
        $detection = $this->detect($repository);
        $this->assertSame(200, $detection['headers']['status-code'], \json_encode($detection['body']));
        $this->assertSame('android', $detection['body']['framework']);
    }

    public function testDetectAndroidVersionCatalog(): void
    {
        $repository = $this->createGiteaRepository([
            'gradle/libs.versions.toml' => "[plugins]\nandroid-application = { id = \"com.android.application\", version = \"8.5.0\" }\n",
            'app/build.gradle.kts' => "plugins {\n    alias(libs.plugins.android.application)\n}\n",
        ]);

        /**
         * Test for SUCCESS
         */
        $detection = $this->detect($repository);
        $this->assertSame(200, $detection['headers']['status-code'], \json_encode($detection['body']));
        $this->assertSame('android', $detection['body']['framework']);
    }

    public function testDetectNothing(): void
    {
        $repository = $this->createGiteaRepository([
            'index.js' => "console.log('server');\n",
            'package.json' => (string) \json_encode(['name' => 'server', 'devDependencies' => ['react-native' => '0.76.0']]),
        ]);

        /**
         * Test for SUCCESS
         */
        $detection = $this->detect($repository);
        $this->assertSame(200, $detection['headers']['status-code'], \json_encode($detection['body']));
        $this->assertSame('distribution', $detection['body']['type']);
        $this->assertSame('', $detection['body']['framework']);

        /**
         * Test for FAILURE
         */
        $unknown = $this->client->call(Client::METHOD_POST, '/vcs/github/installations/' . $this->createGiteaInstallation()['$id'] . '/detections', $this->headers(), [
            'providerRepositoryId' => (string) $repository['id'],
            'type' => 'mobile',
        ]);
        $this->assertSame(400, $unknown['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $unknown['body']['type']);
    }

    public function testListRepositoriesDetectsDistribution(): void
    {
        $installationId = $this->createGiteaInstallation()['$id'];
        $repository = $this->createGiteaRepository(['pubspec.yaml' => self::PUBSPEC]);

        /**
         * Test for SUCCESS
         */
        $this->assertEventually(function () use ($installationId, $repository): void {
            $list = $this->client->call(Client::METHOD_GET, '/vcs/github/installations/' . $installationId . '/providerRepositories', $this->headers(), [
                'type' => 'distribution',
                'search' => $repository['name'],
            ]);
            $this->assertSame(200, $list['headers']['status-code'], \json_encode($list['body']));
            $this->assertSame('distribution', $list['body']['type']);
            $this->assertArrayNotHasKey('frameworkProviderRepositories', $list['body']);

            $frameworks = \array_column($list['body']['distributionProviderRepositories'], 'framework', 'name');
            $this->assertSame('flutter', $frameworks[$repository['name']] ?? null, \json_encode($list['body']));
        }, 30_000, 1000);
    }

    /**
     * @param array<string, mixed> $repository
     * @return array<string, mixed>
     */
    private function detect(array $repository, string $providerRootDirectory = ''): array
    {
        return $this->client->call(Client::METHOD_POST, '/vcs/github/installations/' . $this->createGiteaInstallation()['$id'] . '/detections', $this->headers(), [
            'providerRepositoryId' => (string) $repository['id'],
            'type' => 'distribution',
            'providerRootDirectory' => $providerRootDirectory,
        ]);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function updateLink(string $appId, array $params): array
    {
        return $this->client->call(Client::METHOD_PATCH, '/distribution/apps/' . $appId, $this->headers(), $params);
    }
}
