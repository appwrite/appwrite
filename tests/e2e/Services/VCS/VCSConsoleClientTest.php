<?php

namespace Tests\E2E\Services\VCS;

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

class VCSConsoleClientTest extends Scope
{
    use VCSBase;
    use ProjectCustom;
    use SideConsole;

    public string $providerInstallationId = '42954928'; // appwrite-test
    public string $providerRepositoryId = '705764267'; // ruby-starter (public)
    public string $providerRepositoryId2 = '708688544'; // function1.4 (private)
    public string $providerRepositoryId3 = '943139433'; // svelte-starter (public)
    public string $providerRepositoryId4 = '943245292'; // templates-for-sites (public)

    public function testGitHubAuthorize(): string
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
        $installationId = $response['body']['installationId'];
        return $installationId;
    }

    /**
     * @depends testGitHubAuthorize
     */
    public function testGetInstallation(string $installationId): void
    {
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

    /**
     * @depends testGitHubAuthorize
     */
    public function testDetectRuntime(string $installationId): void
    {
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
        $this->assertEquals($runtime['body']['runtime'], 'ruby-3.3');
        $this->assertEquals($runtime['body']['commands'], 'bundle install && bundle exec rake build');
        $this->assertEquals($runtime['body']['entrypoint'], 'main.rb');

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

    /**
     * @depends testGitHubAuthorize
     */
    public function testDetectFramework(string $installationId)
    {
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
        $this->assertEquals($framework['body']['framework'], 'sveltekit');
        $this->assertEquals($framework['body']['installCommand'], 'npm install');
        $this->assertEquals($framework['body']['buildCommand'], 'npm run build');
        $this->assertEquals($framework['body']['outputDirectory'], './build');

        $framework = $this->client->call(Client::METHOD_POST, '/vcs/github/installations/' . $installationId . '/detections', array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
            'content-type' => 'application/json',
        ], $this->getHeaders()), [
            'providerRepositoryId' => $this->providerRepositoryId4,
            'type' => 'framework',
            'providerRootDirectory' => 'astro/starter'
        ]);

        $this->assertEquals(200, $framework['headers']['status-code']);
        $this->assertEquals($framework['body']['framework'], 'astro');
        $this->assertEquals($framework['body']['installCommand'], 'npm install');
        $this->assertEquals($framework['body']['buildCommand'], 'npm run build');
        $this->assertEquals($framework['body']['outputDirectory'], './dist');

        $framework = $this->client->call(Client::METHOD_POST, '/vcs/github/installations/' . $installationId . '/detections', array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
            'content-type' => 'application/json',
        ], $this->getHeaders()), [
            'providerRepositoryId' => $this->providerRepositoryId4,
            'type' => 'framework',
            'providerRootDirectory' => 'remix/starter'
        ]);

        $this->assertEquals(200, $framework['headers']['status-code']);
        $this->assertEquals($framework['body']['framework'], 'remix');
        $this->assertEquals($framework['body']['installCommand'], 'npm install');
        $this->assertEquals($framework['body']['buildCommand'], 'npm run build');
        $this->assertEquals($framework['body']['outputDirectory'], './build');

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

    /**
     * @depends testGitHubAuthorize
     */
    public function testContents(string $installationId): void
    {
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

    /**
     * @depends testGitHubAuthorize
     */
    public function testListRepositories(string $installationId): void
    {
        /**
         * Test for SUCCESS
         */

        $repositories = $this->client->call(Client::METHOD_GET, '/vcs/github/installations/' . $installationId . '/providerRepositories', array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'type' => 'runtime'
        ]);

        $this->assertEquals(200, $repositories['headers']['status-code']);
        $this->assertEquals($repositories['body']['total'], 4);
        $this->assertEquals($repositories['body']['runtimeProviderRepositories'][0]['name'], 'starter-for-svelte');
        $this->assertEquals($repositories['body']['runtimeProviderRepositories'][0]['organization'], 'appwrite-test');
        $this->assertEquals($repositories['body']['runtimeProviderRepositories'][0]['provider'], 'github');
        $this->assertEquals($repositories['body']['runtimeProviderRepositories'][0]['runtime'], 'node-22');

        $searchedRepositories = $this->client->call(Client::METHOD_GET, '/vcs/github/installations/' . $installationId . '/providerRepositories', array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'search' => 'function1.4',
            'type' => 'runtime'
        ]);
        $this->assertEquals(200, $searchedRepositories['headers']['status-code']);
        $this->assertEquals($searchedRepositories['body']['total'], 1);
        $this->assertEquals($searchedRepositories['body']['runtimeProviderRepositories'][0]['name'], 'function1.4');
        $this->assertEquals($searchedRepositories['body']['runtimeProviderRepositories'][0]['runtime'], 'node-2');

        $searchedRepositories = $this->client->call(Client::METHOD_GET, '/vcs/github/installations/' . $installationId . '/providerRepositories', array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'search' => 'appwrite',
            'type' => 'runtime'
        ]);
        $this->assertEquals(200, $searchedRepositories['headers']['status-code']);
        $this->assertEquals($searchedRepositories['body']['total'], 1);
        $this->assertEquals($searchedRepositories['body']['runtimeProviderRepositories'][0]['name'], 'appwrite');
        $this->assertEquals($searchedRepositories['body']['runtimeProviderRepositories'][0]['runtime'], 'php-8.3');

        $searchedRepositories = $this->client->call(Client::METHOD_GET, '/vcs/github/installations/' . $installationId . '/providerRepositories', array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'search' => 'ruby-starter',
            'type' => 'runtime'
        ]);
        $this->assertEquals(200, $searchedRepositories['headers']['status-code']);
        $this->assertEquals($searchedRepositories['body']['total'], 1);
        $this->assertEquals($searchedRepositories['body']['runtimeProviderRepositories'][0]['name'], 'ruby-starter');
        $this->assertEquals($searchedRepositories['body']['runtimeProviderRepositories'][0]['runtime'], 'ruby-3.3');

        $repositories = $this->client->call(Client::METHOD_GET, '/vcs/github/installations/' . $installationId . '/providerRepositories', array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'type' => 'framework'
        ]);

        $this->assertEquals(200, $repositories['headers']['status-code']);
        $this->assertEquals($repositories['body']['total'], 4);
        $this->assertEquals($repositories['body']['frameworkProviderRepositories'][0]['name'], 'starter-for-svelte');
        $this->assertEquals($repositories['body']['frameworkProviderRepositories'][0]['organization'], 'appwrite-test');
        $this->assertEquals($repositories['body']['frameworkProviderRepositories'][0]['provider'], 'github');
        $this->assertEquals($repositories['body']['frameworkProviderRepositories'][0]['framework'], 'sveltekit');

        $searchedRepositories = $this->client->call(Client::METHOD_GET, '/vcs/github/installations/' . $installationId . '/providerRepositories', array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'search' => 'appwrite',
            'type' => 'runtime'
        ]);
        $this->assertEquals(200, $searchedRepositories['headers']['status-code']);
        $this->assertEquals($searchedRepositories['body']['total'], 1);
        $this->assertEquals($searchedRepositories['body']['runtimeProviderRepositories'][0]['name'], 'appwrite');
        $this->assertEquals($searchedRepositories['body']['runtimeProviderRepositories'][0]['runtime'], 'other');

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
        $this->assertEquals($repositories['body']['total'], 0);
    }

    /**
     * @depends testGitHubAuthorize
     */
    public function testGetRepository(string $installationId): void
    {
        /**
         * Test for SUCCESS
         */

        $repository = $this->client->call(Client::METHOD_GET, '/vcs/github/installations/' . $installationId . '/providerRepositories/' . $this->providerRepositoryId, array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $repository['headers']['status-code']);
        $this->assertEquals($repository['body']['name'], 'ruby-starter');
        $this->assertEquals($repository['body']['organization'], 'appwrite-test');
        $this->assertEquals($repository['body']['private'], false);

        $repository = $this->client->call(Client::METHOD_GET, '/vcs/github/installations/' . $installationId . '/providerRepositories/' . $this->providerRepositoryId2, array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $repository['headers']['status-code']);
        $this->assertEquals($repository['body']['name'], 'function1.4');
        $this->assertEquals($repository['body']['organization'], 'appwrite-test');
        $this->assertEquals($repository['body']['private'], true);

        /**
         * Test for FAILURE
         */

        $repository = $this->client->call(Client::METHOD_GET, '/vcs/github/installations/' . $installationId . '/providerRepositories/randomRepositoryId', array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(404, $repository['headers']['status-code']);
    }

    /**
     * @depends testGitHubAuthorize
     */
    public function testListRepositoryBranches(string $installationId): void
    {
        /**
         * Test for SUCCESS
         */

        $repositoryBranches = $this->client->call(Client::METHOD_GET, '/vcs/github/installations/' . $installationId . '/providerRepositories/' . $this->providerRepositoryId . '/branches', array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $repositoryBranches['headers']['status-code']);
        $this->assertEquals($repositoryBranches['body']['total'], 2);
        $this->assertEquals($repositoryBranches['body']['branches'][0]['name'], 'main');
        $this->assertEquals($repositoryBranches['body']['branches'][1]['name'], 'test');

        /**
         * Test for FAILURE
         */

        $repositoryBranches = $this->client->call(Client::METHOD_GET, '/vcs/github/installations/' . $installationId . '/providerRepositories/randomRepositoryId/branches', array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(404, $repositoryBranches['headers']['status-code']);
    }

    /**
     * @depends testGitHubAuthorize
     */
    public function testCreateFunctionUsingVCS(string $installationId): array
    {
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

        return [
            'installationId' => $installationId,
            'functionId' => $function['body']['$id']
        ];
    }

    /**
     * @depends testCreateFunctionUsingVCS
     */
    public function testUpdateFunctionUsingVCS(array $data): string
    {
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

        return $function['body']['$id'];
    }

    /**
     * @depends testGitHubAuthorize
     */
    public function testCreateRepository(string $installationId): void
    {
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
        $this->assertEquals($result, true);
    }
}
