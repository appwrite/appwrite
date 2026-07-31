<?php

declare(strict_types=1);

namespace Tests\E2E\Services\VCSGitHub;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideConsole;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use Utopia\System\System;
use Utopia\VCS\Adapter\Git\GitHub;

final class VCSGitHubConsoleClientTest extends Scope
{
    use VCSGitHubBase;
    use ProjectCustom;
    use SideConsole;

    public string $providerInstallationId = '42954928'; // appwrite-test
    public string $providerRepositoryId = '705764267'; // ruby-starter (public)
    public string $providerRepositoryId2 = '708688544'; // function1.4 (private)
    public string $providerRepositoryId3 = '943139433'; // svelte-starter (public)
    public string $providerRepositoryId4 = '943245292'; // templates-for-sites (public)

    private static array $cachedInstallationId = [];
    private static array $cachedFunctionData = [];

    /**
     * Helper method to set up GitHub installation.
     * Uses static caching to avoid recreating the installation.
     */
    protected function setupInstallation(): string
    {
        $projectId = $this->getProject()['$id'];

        if (!empty(self::$cachedInstallationId[$projectId])) {
            return self::$cachedInstallationId[$projectId];
        }

        $response = $this->client->call(Client::METHOD_GET, '/mock/github/callback', array_merge([
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'providerInstallationId' => $this->providerInstallationId,
            'projectId' => $projectId,
        ]);

        self::$cachedInstallationId[$projectId] = $response['body']['installationId'];
        return self::$cachedInstallationId[$projectId];
    }

    /**
     * Helper method to set up a function using VCS.
     * Uses static caching to avoid recreating the function.
     */
    protected function setupFunctionUsingVCS(): array
    {
        $projectId = $this->getProject()['$id'];

        if (!empty(self::$cachedFunctionData[$projectId])) {
            return self::$cachedFunctionData[$projectId];
        }

        $installationId = $this->setupInstallation();

        $function = $this->client->call(Client::METHOD_POST, '/functions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'functionId' => ID::unique(),
            'name' => 'Test',
            'execute' => [Role::user($this->getUser()['$id'])->toString()],
            'runtime' => 'php-8.0',
            'entrypoint' => 'index.php',
            'events' => [
                'users.*.create',
                'users.*.delete',
            ],
            'schedule' => '0 0 1 1 *',
            'timeout' => 10,
            'installationId' => $installationId,
            'providerRepositoryId' => $this->providerRepositoryId,
            'providerBranch' => 'main',
        ]);

        self::$cachedFunctionData[$projectId] = [
            'installationId' => $installationId,
            'functionId' => $function['body']['$id']
        ];

        return self::$cachedFunctionData[$projectId];
    }

    public function testGitHubAuthorize(): void
    {
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/mock/github/callback', array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'providerInstallationId' => $this->providerInstallationId,
            'projectId' => $this->getProject()['$id'],
        ]);

        $this->assertNotEmpty($response['body']['installationId']);
    }

    public function testGetInstallation(): void
    {
        $installationId = $this->setupInstallation();

        /**
         * Test for SUCCESS
         */

        $installation = $this->client->call(Client::METHOD_GET, '/vcs/installations/' . $installationId, array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $installation['headers']['status-code']);
        $this->assertEquals('github', $installation['body']['provider']);
        $this->assertEquals('appwrite-test', $installation['body']['organization']);
    }

    public function testDetectRuntime(): void
    {
        $installationId = $this->setupInstallation();

        /**
         * Test for SUCCESS
         */

        $runtime = $this->client->call(Client::METHOD_POST, '/vcs/github/installations/' . $installationId . '/detections', array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
            'content-type' => 'application/json',
        ], $this->getHeaders()), [
            'providerRepositoryId' => $this->providerRepositoryId,
            'type' => 'runtime',
        ]);

        $this->assertEquals(200, $runtime['headers']['status-code']);
        $this->assertEquals('ruby-3.3', $runtime['body']['runtime']);
        $this->assertEquals('bundle install && bundle exec rake build', $runtime['body']['commands']);
        $this->assertEquals('main.rb', $runtime['body']['entrypoint']);

        /**
         * Test for FAILURE
         */

        $runtime = $this->client->call(Client::METHOD_POST, '/vcs/github/installations/' . $installationId . '/detections', array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
            'content-type' => 'application/json',
        ], $this->getHeaders()), [
            'providerRepositoryId' => 'randomRepositoryId', // Invalid repository ID
            'type' => 'runtime',
        ]);

        $this->assertEquals(404, $runtime['headers']['status-code']);
    }

    public function testDetectFramework(): void
    {
        $installationId = $this->setupInstallation();

        /**
         * Test for SUCCESS
         */

        $framework = $this->client->call(Client::METHOD_POST, '/vcs/github/installations/' . $installationId . '/detections', array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
            'content-type' => 'application/json',
        ], $this->getHeaders()), [
            'providerRepositoryId' => $this->providerRepositoryId3,
            'type' => 'framework',
        ]);

        $this->assertEquals(200, $framework['headers']['status-code']);
        $this->assertEquals('sveltekit', $framework['body']['framework']);
        $this->assertEquals('npm install', $framework['body']['installCommand']);
        $this->assertEquals('npm run build', $framework['body']['buildCommand']);
        $this->assertEquals('./build', $framework['body']['outputDirectory']);

        $framework = $this->client->call(Client::METHOD_POST, '/vcs/github/installations/' . $installationId . '/detections', array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
            'content-type' => 'application/json',
        ], $this->getHeaders()), [
            'providerRepositoryId' => $this->providerRepositoryId4,
            'type' => 'framework',
            'providerRootDirectory' => 'astro/starter'
        ]);

        $this->assertEquals(200, $framework['headers']['status-code']);
        $this->assertEquals('astro', $framework['body']['framework']);
        $this->assertEquals('npm install', $framework['body']['installCommand']);
        $this->assertEquals('npm run build', $framework['body']['buildCommand']);
        $this->assertEquals('./dist', $framework['body']['outputDirectory']);

        $framework = $this->client->call(Client::METHOD_POST, '/vcs/github/installations/' . $installationId . '/detections', array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
            'content-type' => 'application/json',
        ], $this->getHeaders()), [
            'providerRepositoryId' => $this->providerRepositoryId4,
            'type' => 'framework',
            'providerRootDirectory' => 'remix/starter'
        ]);

        $this->assertEquals(200, $framework['headers']['status-code']);
        $this->assertEquals('remix', $framework['body']['framework']);
        $this->assertEquals('npm install', $framework['body']['installCommand']);
        $this->assertEquals('npm run build', $framework['body']['buildCommand']);
        $this->assertEquals('./build', $framework['body']['outputDirectory']);

        /**
         * Test for FAILURE
         */

        $framework = $this->client->call(Client::METHOD_POST, '/vcs/github/installations/' . $installationId . '/detections', array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
            'content-type' => 'application/json',
        ], $this->getHeaders()), [
            'providerRepositoryId' => 'randomRepositoryId', // Invalid repository ID
            'type' => 'framework',
        ]);

        $this->assertEquals(404, $framework['headers']['status-code']);
    }

    public function testContents(): void
    {
        $installationId = $this->setupInstallation();

        /**
         * Test for SUCCESS
         */

        $runtime = $this->client->call(Client::METHOD_GET, '/vcs/github/installations/' . $installationId . '/providerRepositories/' . $this->providerRepositoryId . '/contents', array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $runtime['headers']['status-code']);
        $this->assertGreaterThan(0, $runtime['body']['total']);
        $this->assertIsArray($runtime['body']['contents']);
        $this->assertGreaterThan(0, \count($runtime['body']['contents']));

        $gemfileContent = null;
        foreach ($runtime['body']['contents'] as $content) {
            if ($content['name'] === "Gemfile") {
                $gemfileContent = $content;
                break;
            }
        }
        $this->assertNotNull($gemfileContent);
        $this->assertFalse($gemfileContent['isDirectory']);
        $this->assertGreaterThan(0, $gemfileContent['size']); // Should be ~50 bytes
        $this->assertLessThan(100, $gemfileContent['size']);

        $libContent = null;
        foreach ($runtime['body']['contents'] as $content) {
            if ($content['name'] === "lib") {
                $libContent = $content;
                break;
            }
        }
        $this->assertNotNull($libContent);
        $this->assertTrue($libContent['isDirectory']);
        $this->assertEquals(0, $gemfileContent['size']);

        $runtime = $this->client->call(Client::METHOD_GET, '/vcs/github/installations/' . $installationId . '/providerRepositories/' . $this->providerRepositoryId . '/contents?providerRootDirectory=lib', array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $runtime['headers']['status-code']);
        $this->assertGreaterThan(0, $runtime['body']['total']);
        $this->assertIsArray($runtime['body']['contents']);
        $this->assertGreaterThan(0, \count($runtime['body']['contents']));

        $mainRbContent = null;
        foreach ($runtime['body']['contents'] as $content) {
            if ($content['name'] === "main.rb") {
                $mainRbContent = $content;
                break;
            }
        }
        $this->assertNotNull($mainRbContent);
        $this->assertFalse($mainRbContent['isDirectory']);
        $this->assertGreaterThan(0, $gemfileContent['size']);

        /**
         * Test for FAILURE
         */

        $runtime = $this->client->call(Client::METHOD_GET, '/vcs/github/installations/' . $installationId . '/providerRepositories/randomRepositoryId/contents', array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(404, $runtime['headers']['status-code']);
    }

    public function testListRepositories(): void
    {
        $installationId = $this->setupInstallation();

        /**
         * Test for SUCCESS
         */

        $repositories = $this->client->call(Client::METHOD_GET, '/vcs/github/installations/' . $installationId . '/providerRepositories', array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'type' => 'runtime'
        ]);

        $this->assertEquals(200, $repositories['headers']['status-code']);
        $this->assertEquals(4, $repositories['body']['total']);
        $this->assertEquals('starter-for-svelte', $repositories['body']['runtimeProviderRepositories'][0]['name']);
        $this->assertEquals('appwrite-test', $repositories['body']['runtimeProviderRepositories'][0]['organization']);
        $this->assertEquals('github', $repositories['body']['runtimeProviderRepositories'][0]['provider']);
        $this->assertEquals('node-22', $repositories['body']['runtimeProviderRepositories'][0]['runtime']);

        $searchedRepositories = $this->client->call(Client::METHOD_GET, '/vcs/github/installations/' . $installationId . '/providerRepositories', array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'search' => 'function1.4',
            'type' => 'runtime'
        ]);
        $this->assertEquals(200, $searchedRepositories['headers']['status-code']);
        $this->assertEquals(1, $searchedRepositories['body']['total']);
        $this->assertEquals('function1.4', $searchedRepositories['body']['runtimeProviderRepositories'][0]['name']);
        $this->assertEquals('node-2', $searchedRepositories['body']['runtimeProviderRepositories'][0]['runtime']);

        $searchedRepositories = $this->client->call(Client::METHOD_GET, '/vcs/github/installations/' . $installationId . '/providerRepositories', array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'search' => 'appwrite',
            'type' => 'runtime'
        ]);
        $this->assertEquals(200, $searchedRepositories['headers']['status-code']);
        $this->assertEquals(1, $searchedRepositories['body']['total']);
        $this->assertEquals('appwrite', $searchedRepositories['body']['runtimeProviderRepositories'][0]['name']);
        $this->assertEquals('php-8.3', $searchedRepositories['body']['runtimeProviderRepositories'][0]['runtime']);

        $searchedRepositories = $this->client->call(Client::METHOD_GET, '/vcs/github/installations/' . $installationId . '/providerRepositories', array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'search' => 'ruby-starter',
            'type' => 'runtime'
        ]);
        $this->assertEquals(200, $searchedRepositories['headers']['status-code']);
        $this->assertEquals(1, $searchedRepositories['body']['total']);
        $this->assertEquals('ruby-starter', $searchedRepositories['body']['runtimeProviderRepositories'][0]['name']);
        $this->assertEquals('ruby-3.3', $searchedRepositories['body']['runtimeProviderRepositories'][0]['runtime']);

        $repositories = $this->client->call(Client::METHOD_GET, '/vcs/github/installations/' . $installationId . '/providerRepositories', array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'type' => 'framework'
        ]);

        $this->assertEquals(200, $repositories['headers']['status-code']);
        $this->assertEquals(4, $repositories['body']['total']);
        $this->assertEquals('starter-for-svelte', $repositories['body']['frameworkProviderRepositories'][0]['name']);
        $this->assertEquals('appwrite-test', $repositories['body']['frameworkProviderRepositories'][0]['organization']);
        $this->assertEquals('github', $repositories['body']['frameworkProviderRepositories'][0]['provider']);
        $this->assertEquals('sveltekit', $repositories['body']['frameworkProviderRepositories'][0]['framework']);

        $searchedRepositories = $this->client->call(Client::METHOD_GET, '/vcs/github/installations/' . $installationId . '/providerRepositories', array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'search' => 'appwrite',
            'type' => 'runtime'
        ]);
        $this->assertEquals(200, $searchedRepositories['headers']['status-code']);
        $this->assertEquals(1, $searchedRepositories['body']['total']);
        $this->assertEquals('appwrite', $searchedRepositories['body']['runtimeProviderRepositories'][0]['name']);
        $this->assertEquals('other', $searchedRepositories['body']['runtimeProviderRepositories'][0]['runtime']);

        // with limit and offset
        $repositories = $this->client->call(Client::METHOD_GET, '/vcs/github/installations/' . $installationId . '/providerRepositories', array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'type' => 'runtime',
            'limit' => Query::limit(1)->toString(),
            'offset' => Query::offset(0)->toString()
        ]);
        $this->assertSame(200, $repositories['headers']['status-code']);
        $this->assertSame(4, $repositories['body']['total']);
        $this->assertCount(1, $repositories['body']['runtimeProviderRepositories']);
        $this->assertSame('starter-for-svelte', $repositories['body']['runtimeProviderRepositories'][0]['name']);

        $repositories = $this->client->call(Client::METHOD_GET, '/vcs/github/installations/' . $installationId . '/providerRepositories', array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'type' => 'runtime',
            'limit' => Query::limit(2)->toString(),
            'offset' => Query::offset(2)->toString()
        ]);
        $this->assertSame(200, $repositories['headers']['status-code']);
        $this->assertSame(4, $repositories['body']['total']);
        $this->assertCount(2, $repositories['body']['runtimeProviderRepositories']);
        $this->assertSame('appwrite', $repositories['body']['runtimeProviderRepositories'][0]['name']);
        $this->assertSame('ruby-starter', $repositories['body']['runtimeProviderRepositories'][1]['name']);

        $repositories = $this->client->call(Client::METHOD_GET, '/vcs/github/installations/' . $installationId . '/providerRepositories', array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'type' => 'runtime',
            'limit' => Query::limit(2)->toString(),
            'offset' => Query::offset(100)->toString()
        ]);
        $this->assertSame(200, $repositories['headers']['status-code']);
        $this->assertSame(4, $repositories['body']['total']);
        $this->assertCount(0, $repositories['body']['runtimeProviderRepositories']);

        // TODO: If you are about to add another check, rewrite this to @provideScenarios

        /**
         * Test for FAILURE
         */

        $repositories = $this->client->call(Client::METHOD_GET, '/vcs/github/installations/randomInstallationId/providerRepositories', array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'type' => 'runtime'
        ]);

        $this->assertEquals(404, $repositories['headers']['status-code']);

        $repositories = $this->client->call(Client::METHOD_GET, '/vcs/github/installations/' . $installationId . '/providerRepositories', array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'type' => 'randomType'
        ]);

        $this->assertEquals(400, $repositories['headers']['status-code']);

        // invalid offset
        $repositories = $this->client->call(Client::METHOD_GET, '/vcs/github/installations/' . $installationId . '/providerRepositories', array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'type' => 'runtime',
            'limit' => Query::limit(2)->toString(),
            'offset' => Query::offset(1)->toString()
        ]);
        $this->assertEquals(400, $repositories['headers']['status-code']);
        $this->assertEquals('offset must be a multiple of the limit', $repositories['body']['message']);

        $repositories = $this->client->call(Client::METHOD_GET, '/vcs/github/installations/' . $installationId . '/providerRepositories', array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'search' => 'randomSearch',
            'type' => 'framework'
        ]);

        $this->assertEquals(200, $repositories['headers']['status-code']);
        $this->assertEquals(0, $repositories['body']['total']);
    }

    public function testGetRepository(): void
    {
        $installationId = $this->setupInstallation();

        /**
         * Test for SUCCESS
         */

        $repository = $this->client->call(Client::METHOD_GET, '/vcs/github/installations/' . $installationId . '/providerRepositories/' . $this->providerRepositoryId, array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $repository['headers']['status-code']);
        $this->assertEquals('ruby-starter', $repository['body']['name']);
        $this->assertEquals('appwrite-test', $repository['body']['organization']);
        $this->assertEquals(false, $repository['body']['private']);

        $repository = $this->client->call(Client::METHOD_GET, '/vcs/github/installations/' . $installationId . '/providerRepositories/' . $this->providerRepositoryId2, array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $repository['headers']['status-code']);
        $this->assertEquals('function1.4', $repository['body']['name']);
        $this->assertEquals('appwrite-test', $repository['body']['organization']);
        $this->assertEquals(true, $repository['body']['private']);

        /**
         * Test for FAILURE
         */

        $repository = $this->client->call(Client::METHOD_GET, '/vcs/github/installations/' . $installationId . '/providerRepositories/randomRepositoryId', array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(404, $repository['headers']['status-code']);
    }

    public function testListRepositoryBranches(): void
    {
        $installationId = $this->setupInstallation();

        /**
         * Test for SUCCESS
         */

        $repositoryBranches = $this->client->call(Client::METHOD_GET, '/vcs/github/installations/' . $installationId . '/providerRepositories/' . $this->providerRepositoryId . '/branches', array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $repositoryBranches['headers']['status-code']);
        $this->assertEquals(2, $repositoryBranches['body']['total']);
        $this->assertEquals('main', $repositoryBranches['body']['branches'][0]['name']);
        $this->assertEquals('test', $repositoryBranches['body']['branches'][1]['name']);

        $repositoryBranches = $this->client->call(Client::METHOD_GET, '/vcs/github/installations/' . $installationId . '/providerRepositories/' . $this->providerRepositoryId . '/branches', array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'search' => 'tes',
        ]);

        $this->assertEquals(200, $repositoryBranches['headers']['status-code']);
        $this->assertEquals(1, $repositoryBranches['body']['total']);
        $this->assertCount(1, $repositoryBranches['body']['branches']);
        $this->assertEquals('test', $repositoryBranches['body']['branches'][0]['name']);

        $repositoryBranches = $this->client->call(Client::METHOD_GET, '/vcs/github/installations/' . $installationId . '/providerRepositories/' . $this->providerRepositoryId . '/branches', array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::limit(1)->toString(),
                Query::offset(1)->toString(),
            ],
        ]);

        $this->assertEquals(200, $repositoryBranches['headers']['status-code']);
        $this->assertEquals(2, $repositoryBranches['body']['total']);
        $this->assertCount(1, $repositoryBranches['body']['branches']);
        $this->assertEquals('test', $repositoryBranches['body']['branches'][0]['name']);

        $repositoryBranches = $this->client->call(Client::METHOD_GET, '/vcs/github/installations/' . $installationId . '/providerRepositories/' . $this->providerRepositoryId . '/branches', array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::limit(1)->toString(),
                Query::cursorAfter(new \Utopia\Database\Document(['$id' => 'main']))->toString(),
            ],
        ]);

        $this->assertEquals(200, $repositoryBranches['headers']['status-code']);
        $this->assertEquals(2, $repositoryBranches['body']['total']);
        $this->assertCount(1, $repositoryBranches['body']['branches']);
        $this->assertEquals('test', $repositoryBranches['body']['branches'][0]['name']);

        $repositoryBranches = $this->client->call(Client::METHOD_GET, '/vcs/github/installations/' . $installationId . '/providerRepositories/' . $this->providerRepositoryId . '/branches', array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::limit(1)->toString(),
                Query::cursorBefore(new \Utopia\Database\Document(['$id' => 'test']))->toString(),
            ],
        ]);

        $this->assertEquals(200, $repositoryBranches['headers']['status-code']);
        $this->assertEquals(2, $repositoryBranches['body']['total']);
        $this->assertCount(1, $repositoryBranches['body']['branches']);
        $this->assertEquals('main', $repositoryBranches['body']['branches'][0]['name']);

        /**
         * Test for FAILURE
         */

        $repositoryBranches = $this->client->call(Client::METHOD_GET, '/vcs/github/installations/' . $installationId . '/providerRepositories/randomRepositoryId/branches', array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(404, $repositoryBranches['headers']['status-code']);

        $repositoryBranches = $this->client->call(Client::METHOD_GET, '/vcs/github/installations/' . $installationId . '/providerRepositories/' . $this->providerRepositoryId . '/branches', array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::cursorAfter(new \Utopia\Database\Document(['$id' => 'missing-branch']))->toString(),
            ],
        ]);

        $this->assertEquals(400, $repositoryBranches['headers']['status-code']);
    }

    public function testCreateFunctionUsingVCS(): void
    {
        $installationId = $this->setupInstallation();

        $function = $this->client->call(Client::METHOD_POST, '/functions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'functionId' => ID::unique(),
            'name' => 'Test',
            'execute' => [Role::user($this->getUser()['$id'])->toString()],
            'runtime' => 'php-8.0',
            'entrypoint' => 'index.php',
            'events' => [
                'users.*.create',
                'users.*.delete',
            ],
            'schedule' => '0 0 1 1 *',
            'timeout' => 10,
            'installationId' => $installationId,
            'providerRepositoryId' => $this->providerRepositoryId,
            'providerBranch' => 'main',
        ]);

        $this->assertEquals(201, $function['headers']['status-code']);
        $this->assertEquals('Test', $function['body']['name']);
        $this->assertEquals('php-8.0', $function['body']['runtime']);
        $this->assertEquals('index.php', $function['body']['entrypoint']);
        $this->assertEquals('705764267', $function['body']['providerRepositoryId']);
        $this->assertEquals('main', $function['body']['providerBranch']);
    }

    public function testGitHubPushCreatesFunctionDeploymentWithoutProjectHeader(): void
    {
        $data = $this->setupFunctionUsingVCS();
        $github = new GitHub(new Cache(new None()));
        $github->initializeVariables(
            $this->providerInstallationId,
            System::getEnv('_APP_VCS_GITHUB_PRIVATE_KEY'),
            System::getEnv('_APP_VCS_GITHUB_APP_ID'),
        );
        $commit = $github->getLatestCommit('appwrite-test', 'ruby-starter', 'main');
        $payload = [
            'created' => false,
            'deleted' => false,
            'ref' => 'refs/heads/main',
            'before' => $commit['commitHash'],
            'after' => $commit['commitHash'],
            'repository' => [
                'id' => (int) $this->providerRepositoryId,
                'name' => 'ruby-starter',
                'full_name' => 'appwrite-test/ruby-starter',
                'private' => false,
                'html_url' => 'https://github.com/appwrite-test/ruby-starter',
                'owner' => ['name' => 'appwrite-test'],
            ],
            'installation' => ['id' => (int) $this->providerInstallationId],
            'head_commit' => [
                'author' => [
                    'name' => $commit['commitAuthor'],
                    'email' => 'vcs-e2e@appwrite.io',
                ],
                'message' => $commit['commitMessage'],
                'url' => $commit['commitUrl'],
            ],
            'commits' => [[
                'id' => $commit['commitHash'],
                'added' => [],
                'removed' => [],
                'modified' => ['main.rb'],
            ]],
            'sender' => [
                'html_url' => $commit['commitAuthorUrl'],
                'avatar_url' => $commit['commitAuthorAvatar'],
            ],
        ];
        $headers = [
            'content-type' => 'application/json',
            'x-github-event' => 'push',
        ];
        $secret = System::getEnv('_APP_VCS_GITHUB_WEBHOOK_SECRET', '');
        if (!empty($secret)) {
            $headers['x-hub-signature-256'] = 'sha256=' . \hash_hmac(
                'sha256',
                \json_encode($payload, JSON_THROW_ON_ERROR),
                $secret,
            );
        }

        // GitHub webhooks are public and intentionally have no x-appwrite-project header.
        $event = $this->client->call(Client::METHOD_POST, '/vcs/github/events', $headers, $payload);

        $this->assertEquals(200, $event['headers']['status-code']);

        $deployments = $this->client->call(Client::METHOD_GET, '/functions/' . $data['functionId'] . '/deployments', array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::equal('providerCommitHash', [$commit['commitHash']])->toString(),
                Query::equal('type', ['vcs'])->toString(),
            ],
        ]);

        $this->assertEquals(200, $deployments['headers']['status-code']);
        $this->assertGreaterThanOrEqual(1, $deployments['body']['total']);
        $this->assertSame($data['functionId'], $deployments['body']['deployments'][0]['resourceId']);
    }

    public function testUpdateFunctionUsingVCS(): void
    {
        $data = $this->setupFunctionUsingVCS();

        $function = $this->client->call(Client::METHOD_PUT, '/functions/' . $data['functionId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'functionId' => ID::unique(),
            'name' => 'Test',
            'execute' => [Role::user($this->getUser()['$id'])->toString()],
            'runtime' => 'php-8.0',
            'entrypoint' => 'index.php',
            'events' => [
                'users.*.create',
                'users.*.delete',
            ],
            'schedule' => '0 0 1 1 *',
            'timeout' => 10,
            'installationId' => $data['installationId'],
            'providerRepositoryId' => $this->providerRepositoryId2,
            'providerBranch' => 'main',
        ]);

        $this->assertEquals(200, $function['headers']['status-code']);
        $this->assertEquals('Test', $function['body']['name']);
        $this->assertEquals('php-8.0', $function['body']['runtime']);
        $this->assertEquals('index.php', $function['body']['entrypoint']);
        $this->assertEquals('708688544', $function['body']['providerRepositoryId']);
        $this->assertEquals('main', $function['body']['providerBranch']);
    }

    public function testUpdateFunctionOmitProviderRepositoryIdPreservesVcs(): void
    {
        $data = $this->setupFunctionUsingVCS();

        // Omit providerRepositoryId entirely — should preserve VCS connection, not clear it
        $function = $this->client->call(Client::METHOD_PUT, '/functions/' . $data['functionId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Test',
            'runtime' => 'php-8.0',
            'entrypoint' => 'index.php',
            'timeout' => 10,
        ]);

        $this->assertEquals(200, $function['headers']['status-code']);
        $this->assertNotEmpty($function['body']['providerRepositoryId']);
        $this->assertNotEmpty($function['body']['installationId']);
        $this->assertNotEmpty($function['body']['providerBranch']);
    }

    public function testUpdateSiteOmitProviderRepositoryIdPreservesVcs(): void
    {
        $installationId = $this->setupInstallation();

        $site = $this->client->call(Client::METHOD_POST, '/sites', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'siteId' => ID::unique(),
            'name' => 'Test Site VCS',
            'framework' => 'other',
            'buildRuntime' => 'node-22',
            'installationId' => $installationId,
            'providerRepositoryId' => $this->providerRepositoryId3,
            'providerBranch' => 'main',
        ]);

        $this->assertEquals(201, $site['headers']['status-code']);
        $siteId = $site['body']['$id'];

        // Omit providerRepositoryId — should preserve VCS connection, not clear it
        $updated = $this->client->call(Client::METHOD_PUT, '/sites/' . $siteId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Test Site VCS',
            'framework' => 'other',
            'buildRuntime' => 'node-22',
        ]);

        $this->assertEquals(200, $updated['headers']['status-code']);
        $this->assertNotEmpty($updated['body']['providerRepositoryId']);
        $this->assertNotEmpty($updated['body']['installationId']);
        $this->assertNotEmpty($updated['body']['providerBranch']);

        $this->client->call(Client::METHOD_DELETE, '/sites/' . $siteId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
    }

    public function testCrossProjectInstallationRejected(): void
    {
        $installationId = $this->setupInstallation();
        $consoleHeaders = [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-project' => 'console',
        ];

        $team = $this->client->call(Client::METHOD_POST, '/teams', $consoleHeaders, [
            'teamId' => ID::unique(),
            'name' => 'Cross Project Team',
        ]);
        $this->assertEquals(201, $team['headers']['status-code']);

        $project2 = $this->client->call(Client::METHOD_POST, '/projects', $consoleHeaders, [
            'projectId' => ID::unique(),
            'name' => 'Cross Project Test',
            'teamId' => $team['body']['$id'],
            'region' => System::getEnv('_APP_REGION', 'default'),
        ]);
        $this->assertEquals(201, $project2['headers']['status-code']);
        $project2Id = $project2['body']['$id'];

        $key = $this->client->call(Client::METHOD_POST, '/projects/' . $project2Id . '/keys', $consoleHeaders, [
            'keyId' => ID::unique(),
            'name' => 'Test Key',
            'scopes' => ['functions.write', 'sites.write'],
        ]);
        $this->assertEquals(201, $key['headers']['status-code']);

        $headers2 = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $project2Id,
            'x-appwrite-key' => $key['body']['secret'],
        ];

        try {
            // createFunction with installation from project 1 → should fail
            $function = $this->client->call(Client::METHOD_POST, '/functions', $headers2, [
                'functionId' => ID::unique(),
                'name' => 'Test Cross',
                'runtime' => 'php-8.0',
                'entrypoint' => 'index.php',
                'installationId' => $installationId,
                'providerRepositoryId' => $this->providerRepositoryId,
                'providerBranch' => 'main',
            ]);
            $this->assertEquals(404, $function['headers']['status-code']);

            // createSite with installation from project 1 → should fail
            $site = $this->client->call(Client::METHOD_POST, '/sites', $headers2, [
                'siteId' => ID::unique(),
                'name' => 'Test Cross Site',
                'framework' => 'other',
                'buildRuntime' => 'node-22',
                'installationId' => $installationId,
                'providerRepositoryId' => $this->providerRepositoryId3,
                'providerBranch' => 'main',
            ]);
            $this->assertEquals(404, $site['headers']['status-code']);

            // updateFunction with cross-project installation → should fail
            $fn = $this->client->call(Client::METHOD_POST, '/functions', $headers2, [
                'functionId' => ID::unique(),
                'name' => 'Test No VCS',
                'runtime' => 'php-8.0',
                'entrypoint' => 'index.php',
            ]);
            $this->assertEquals(201, $fn['headers']['status-code']);

            $updated = $this->client->call(Client::METHOD_PUT, '/functions/' . $fn['body']['$id'], $headers2, [
                'name' => 'Test No VCS',
                'runtime' => 'php-8.0',
                'entrypoint' => 'index.php',
                'installationId' => $installationId,
                'providerRepositoryId' => $this->providerRepositoryId,
                'providerBranch' => 'main',
            ]);
            $this->assertEquals(404, $updated['headers']['status-code']);

            // updateSite with cross-project installation → should fail
            $siteNoVcs = $this->client->call(Client::METHOD_POST, '/sites', $headers2, [
                'siteId' => ID::unique(),
                'name' => 'Test No VCS Site',
                'framework' => 'other',
                'buildRuntime' => 'node-22',
            ]);
            $this->assertEquals(201, $siteNoVcs['headers']['status-code']);

            $updatedSite = $this->client->call(Client::METHOD_PUT, '/sites/' . $siteNoVcs['body']['$id'], $headers2, [
                'name' => 'Test No VCS Site',
                'framework' => 'other',
                'buildRuntime' => 'node-22',
                'installationId' => $installationId,
                'providerRepositoryId' => $this->providerRepositoryId3,
                'providerBranch' => 'main',
            ]);
            $this->assertEquals(404, $updatedSite['headers']['status-code']);
        } finally {
            $this->client->call(Client::METHOD_DELETE, '/projects/' . $project2Id, $consoleHeaders);
            $this->client->call(Client::METHOD_DELETE, '/teams/' . $team['body']['$id'], $consoleHeaders);
        }
    }

    public function testCreateRepository(): void
    {
        $installationId = $this->setupInstallation();

        /**
         * Test for SUCCESS
         */

        $github = new GitHub(new Cache(new None()));
        $privateKey = System::getEnv('_APP_VCS_GITHUB_PRIVATE_KEY');
        $githubAppId = System::getEnv('_APP_VCS_GITHUB_APP_ID');
        $github->initializeVariables($this->providerInstallationId, $privateKey, $githubAppId);

        $repository = $this->client->call(Client::METHOD_POST, '/vcs/github/installations/' . $installationId . '/providerRepositories', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'test-repo-1',
            'private' => true
        ]);

        $this->assertEquals('test-repo-1', $repository['body']['name']);
        $this->assertEquals('appwrite-test', $repository['body']['organization']);
        $this->assertEquals('github', $repository['body']['provider']);

        /**
         * Test for FAILURE
         */

        $repository = $this->client->call(Client::METHOD_POST, '/vcs/github/installations/' . $installationId . '/providerRepositories', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'test-repo-1',
            'private' => true
        ]);

        $this->assertEquals(400, $repository['headers']['status-code']);
        $this->assertEquals('Provider Error: Repository creation failed. name already exists on this account', $repository['body']['message']);

        /**
         * Test for SUCCESS
         */

        $result = $github->deleteRepository('appwrite-test', 'test-repo-1');
        $this->assertTrue($result);
    }
}
