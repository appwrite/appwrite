<?php

namespace Tests\E2E\Services\VCS;

use Tests\E2E\Scopes\Scope;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\SideConsole;

class VCSConsoleClientTest extends Scope
{
    use ProjectCustom;
    use SideConsole;

    public function testDetectRuntime(string $installationId = '651c6d27dac46cc3bc99', string $providerRepositoryId = '700022615')
    {
        /**
         * Test for SUCCESS
         */

        $runtime = $this->client->call(Client::METHOD_POST, '/vcs/github/installations/' . $installationId . '/providerRepositories/' . $providerRepositoryId . '/detection', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'installationId' => $installationId,
            'providerRepositoryId' => $providerRepositoryId
        ]);

        $this->assertEquals(200, $runtime['headers']['status-code']);
        $this->assertEquals($runtime['body']['runtime'], 'ruby-3.1');

        /**
         * Test for FAILURE
         */

        // $runtime = $this->client->call(Client::METHOD_POST, '/vcs/github/installations/' . $installationId . '/providerRepositories/' . $providerRepositoryId .'/detection', array_merge([
        //     'content-type' => 'application/json',
        //     'x-appwrite-project' => $this->getProject()['$id'],
        // ], $this->getHeaders()), [
        //     'installationId' => $installationId,
        //     'providerRepositoryId' => $providerRepositoryId,
        //     'providerRootDirectory' => 'src'
        // ]);

        // $this->assertEquals(404, $runtime['headers']['status-code']);
    }

    public function testListRepositories(string $installationId = '651c6d27dac46cc3bc99')
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
    }

    public function testGetRepository(string $installationId = '651c6d27dac46cc3bc99', string $providerRepositoryId = '700022615', string $providerRepositoryId2 = '700020051')
    {
        /**
         * Test for SUCCESS
         */

        $repository = $this->client->call(Client::METHOD_GET, '/vcs/github/installations/' . $installationId . '/providerRepositories/' . $providerRepositoryId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'installationId' => $installationId,
            'providerRepositoryId' => $providerRepositoryId
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
    }

    public function testListRepositoryBranches(string $installationId = '651c6d27dac46cc3bc99', string $providerRepositoryId = '700020051')
    {
        /**
         * Test for SUCCESS
         */

        $repositoryBranches = $this->client->call(Client::METHOD_GET, '/vcs/github/installations/' . $installationId . '/providerRepositories/' . $providerRepositoryId . '/branches', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'installationId' => $installationId,
            'providerRepositoryId' => $providerRepositoryId
        ]);

        $this->assertEquals(200, $repositoryBranches['headers']['status-code']);
        $this->assertEquals($repositoryBranches['body']['total'], 2);
        $this->assertEquals($repositoryBranches['body']['branches'][0]['name'], 'main');
        $this->assertEquals($repositoryBranches['body']['branches'][1]['name'], 'test');
    }

    // public function testGetInstallation(string $installationId = '651c6d27dac46cc3bc99')
    // {
    //     /**
    //      * Test for SUCCESS
    //      */

    //     $installation = $this->client->call(Client::METHOD_GET, '/vcs/installations/' . $installationId, array_merge([
    //         'content-type' => 'application/json',
    //         'x-appwrite-project' => $this->getProject()['$id'],
    //     ], $this->getHeaders()), [
    //         'installationId' => $installationId
    //     ]);

    //     $this->assertEquals(200, $installation['headers']['status-code']);
    // }
}
