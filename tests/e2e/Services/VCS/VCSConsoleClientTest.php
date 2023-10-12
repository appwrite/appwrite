<?php

namespace Tests\E2E\Services\VCS;

use Tests\E2E\Scopes\Scope;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\SideConsole;
use Utopia\App;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Role;

class VCSConsoleClientTest extends Scope
{
    use ProjectCustom;
    use SideConsole;

    public ?string $providerRepositoryId = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->providerRepositoryId = App::getEnv('_APP_VCS_TEST_PROVIDER_REPOSITORY_ID');
    }

    public function testGitHubAuthorize()
    {
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/mock/github/callback', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'installation_id' => App::getEnv('_APP_VCS_TEST_GITHUB_INSTALLATION_ID'),
            'projectId' => $this->getProject()['$id'],
        ]);

        $this->assertNotEmpty($response['body']['installationId']);
        $installationId = $response['body']['installationId'];
        return $installationId;
    }

    /**
     * @depends testGitHubAuthorize
     */
    public function testGetInstallation(string $installationId)
    {
        /**
         * Test for SUCCESS
         */

        $installation = $this->client->call(Client::METHOD_GET, '/vcs/installations/' . $installationId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'installationId' => $installationId
        ]);

        $this->assertEquals(200, $installation['headers']['status-code']);
    }

    /**
     * @depends testGitHubAuthorize
     */
    public function testDetectRuntime(string $installationId)
    {
        /**
         * Test for SUCCESS
         */

        $runtime = $this->client->call(Client::METHOD_POST, '/vcs/github/installations/' . $installationId . '/providerRepositories/' . $this->providerRepositoryId . '/detection', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'installationId' => $installationId,
            'providerRepositoryId' => $this->providerRepositoryId
        ]);

        $this->assertEquals(200, $runtime['headers']['status-code']);
        $this->assertEquals($runtime['body']['runtime'], 'ruby-3.1');

        /**
         * Test for FAILURE
         */

        // $runtime = $this->client->call(Client::METHOD_POST, '/vcs/github/installations/' . $installationId . '/providerRepositories/randomRepositoryId/detection', array_merge([
        //     'content-type' => 'application/json',
        //     'x-appwrite-project' => $this->getProject()['$id'],
        // ], $this->getHeaders()), [
        //     'installationId' => $installationId,
        //     'providerRepositoryId' => 'randomRepositoryId'
        // ]);

        // $this->assertEquals(404, $runtime['headers']['status-code']); 
        // TODO: throw 404 from GitHub.php if repo not found

        // $runtime = $this->client->call(Client::METHOD_POST, '/vcs/github/installations/' . $installationId . '/providerRepositories/' . $this->providerRepositoryId .'/detection', array_merge([
        //     'content-type' => 'application/json',
        //     'x-appwrite-project' => $this->getProject()['$id'],
        // ], $this->getHeaders()), [
        //     'installationId' => $installationId,
        //     'providerRepositoryId' => $this->providerRepositoryId,
        //     'providerRootDirectory' => ''̦
        // ]);

        // $this->assertEquals(404, $runtime['headers']['status-code']);
    }

    /**
     * @depends testGitHubAuthorize
     */
    public function testListRepositories(string $installationId)
    {
        /**
         * Test for SUCCESS
         */

        $repositories = $this->client->call(Client::METHOD_GET, '/vcs/github/installations/' . $installationId . '/providerRepositories', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'installationId' => $installationId
        ]);

        $this->assertEquals(200, $repositories['headers']['status-code']);
        $this->assertEquals($repositories['body']['total'], 3);

        $searchedRepositories = $this->client->call(Client::METHOD_GET, '/vcs/github/installations/' . $installationId . '/providerRepositories', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'installationId' => $installationId,
            'search' => 'func'
        ]);

        $this->assertEquals(200, $searchedRepositories['headers']['status-code']);
        $this->assertEquals($searchedRepositories['body']['total'], 1);

        /**
         * Test for FAILURE
         */

        $repositories = $this->client->call(Client::METHOD_GET, '/vcs/github/installations/randomInstallationId/providerRepositories', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'installationId' => 'randomInstallationId'
        ]);

        $this->assertEquals(404, $repositories['headers']['status-code']);
    }

    /**
     * @depends testGitHubAuthorize
     */
    public function testGetRepository(string $installationId, string $providerRepositoryId2 = '700020051')
    {
        /**
         * Test for SUCCESS
         */

        $repository = $this->client->call(Client::METHOD_GET, '/vcs/github/installations/' . $installationId . '/providerRepositories/' . $this->providerRepositoryId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'installationId' => $installationId,
            'providerRepositoryId' => $this->providerRepositoryId
        ]);

        $this->assertEquals(200, $repository['headers']['status-code']);
        $this->assertEquals($repository['body']['name'], 'ruby-starter');

        $repository = $this->client->call(Client::METHOD_GET, '/vcs/github/installations/' . $installationId . '/providerRepositories/' . $providerRepositoryId2, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'installationId' => $installationId,
            'providerRepositoryId' => $providerRepositoryId2
        ]);

        $this->assertEquals(200, $repository['headers']['status-code']);
        $this->assertEquals($repository['body']['name'], 'function-1.4');

        /**
         * Test for FAILURE
         */

        // $repository = $this->client->call(Client::METHOD_GET, '/vcs/github/installations/' . $installationId . '/providerRepositories/randomRepositoryId', array_merge([
        //     'content-type' => 'application/json',
        //     'x-appwrite-project' => $this->getProject()['$id'],
        // ], $this->getHeaders()), [
        //     'installationId' => $installationId,
        //     'providerRepositoryId' => 'randomRepositoryId'
        // ]);

        // $this->assertEquals(404, $repository['headers']['status-code']);
        // TODO: Throw 404 if repository not found
    }

    /**
     * @depends testGitHubAuthorize
     */
    public function testListRepositoryBranches(string $installationId)
    {
        /**
         * Test for SUCCESS
         */

        $repositoryBranches = $this->client->call(Client::METHOD_GET, '/vcs/github/installations/' . $installationId . '/providerRepositories/' . $this->providerRepositoryId . '/branches', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'installationId' => $installationId,
            'providerRepositoryId' => $this->providerRepositoryId
        ]);

        $this->assertEquals(200, $repositoryBranches['headers']['status-code']);
        $this->assertEquals($repositoryBranches['body']['total'], 2);
        $this->assertEquals($repositoryBranches['body']['branches'][0]['name'], 'main');
        $this->assertEquals($repositoryBranches['body']['branches'][1]['name'], 'test');

        /**
         * Test for FAILURE
         */

        // $repositoryBranches = $this->client->call(Client::METHOD_GET, '/vcs/github/installations/' . $installationId . '/providerRepositories/randomRepositoryId/branches', array_merge([
        //     'content-type' => 'application/json',
        //     'x-appwrite-project' => $this->getProject()['$id'],
        // ], $this->getHeaders()), [
        //     'installationId' => $installationId,
        //     'providerRepositoryId' => 'randomRepositoryId'
        // ]);

        // $this->assertEquals(404, $repositoryBranches['headers']['status-code']);
        // TODO: Throw error from listBranches 
    }

    /**
     * @depends testGitHubAuthorize
     */
    public function testCreateFunctionUsingVCS(string $installationId)
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

        return [
            'installationId' => $installationId,
            'functionId' => $function['body']['$id']
        ];
    }

    /**
     * @depends testCreateFunctionUsingVCS
     */
    public function testUpdateFunctionUsingVCS(array $data, string $providerRepositoryId2 = '700020051')
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
            'providerRepositoryId' => $providerRepositoryId2,
            'providerBranch' => 'main',
        ]);

        $this->assertEquals(200, $function['headers']['status-code']);

        return $function['body']['$id'];
    }
}
