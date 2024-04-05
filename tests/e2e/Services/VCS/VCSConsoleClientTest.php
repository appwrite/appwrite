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
use Utopia\System\System;
use Utopia\VCS\Adapter\Git\GitHub;

class VCSConsoleClientTest extends Scope
{
    use VCSBase;
    use ProjectCustom;
    use SideConsole;

    public string $providerInstallationId = '42954928';
    public string $providerRepositoryId = '705764267';
    public string $providerRepositoryId2 = '708688544';

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

        $runtime = $this->client->call(Client::METHOD_POST, '/vcs/github/installations/' . $installationId . '/providerRepositories/' . $this->providerRepositoryId . '/detection', array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $runtime['headers']['status-code']);
        $this->assertEquals($runtime['body']['runtime'], 'ruby-3.1');

        /**
         * Test for FAILURE
         */

        $runtime = $this->client->call(Client::METHOD_POST, '/vcs/github/installations/' . $installationId . '/providerRepositories/randomRepositoryId/detection', array_merge([
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
        ], $this->getHeaders()));

        $this->assertEquals(200, $repositories['headers']['status-code']);
        $this->assertEquals($repositories['body']['total'], 3);
        $this->assertEquals($repositories['body']['providerRepositories'][0]['name'], 'function1.4');
        $this->assertEquals($repositories['body']['providerRepositories'][0]['organization'], 'appwrite-test');
        $this->assertEquals($repositories['body']['providerRepositories'][0]['provider'], 'github');
        $this->assertEquals($repositories['body']['providerRepositories'][1]['name'], 'appwrite');
        $this->assertEquals($repositories['body']['providerRepositories'][2]['name'], 'ruby-starter');


        $searchedRepositories = $this->client->call(Client::METHOD_GET, '/vcs/github/installations/' . $installationId . '/providerRepositories', array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'search' => 'func'
        ]);

        $this->assertEquals(200, $searchedRepositories['headers']['status-code']);
        $this->assertEquals($searchedRepositories['body']['total'], 1);
        $this->assertEquals($searchedRepositories['body']['providerRepositories'][0]['name'], 'function1.4');

        /**
         * Test for FAILURE
         */

        $repositories = $this->client->call(Client::METHOD_GET, '/vcs/github/installations/randomInstallationId/providerRepositories', array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(404, $repositories['headers']['status-code']);
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
