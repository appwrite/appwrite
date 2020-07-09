<?php

namespace Tests\E2E\Services\Teams;

use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\ProjectConsole;
use Tests\E2E\Scopes\SideClient;
use Tests\E2E\Services\Projects\ProjectsBase;
use Tests\E2E\Client;

class ProjectsConsoleClientTest extends Scope
{
    use ProjectsBase;
    use ProjectConsole;
    use SideClient;

    public function testCreateProject(): array
    {
        /**
         * Test for SUCCESS
         */
        $team = $this->client->call(Client::METHOD_POST, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Project Test',
        ]);

        $this->assertEquals(201, $team['headers']['status-code']);
        $this->assertEquals('Project Test', $team['body']['name']);
        $this->assertNotEmpty($team['body']['$id']);

        $response = $this->client->call(Client::METHOD_POST, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Project Test',
            'teamId' => $team['body']['$id'],
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals('Project Test', $response['body']['name']);
        $this->assertEquals($team['body']['$id'], $response['body']['teamId']);
        $this->assertArrayHasKey('platforms', $response['body']);
        $this->assertArrayHasKey('webhooks', $response['body']);
        $this->assertArrayHasKey('keys', $response['body']);
        $this->assertArrayHasKey('tasks', $response['body']);

        $projectId = $response['body']['$id'];

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_POST, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => '',
            'teamId' => $team['body']['$id'],
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
        
        $response = $this->client->call(Client::METHOD_POST, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Project Test',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        return ['projectId' => $projectId];
    }

    /**
     * @depends testCreateProject
     */
    public function testListProject($data):array
    {
        $id = (isset($data['projectId'])) ? $data['projectId'] : '';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertEquals($id, $response['body']['projects'][0]['$id']);
        $this->assertEquals('Project Test', $response['body']['projects'][0]['name']);

        /**
         * Test for FAILURE
         */

        return $data;
    }

    /**
     * @depends testCreateProject
     */
    public function testGetProject($data):array
    {
        $id = (isset($data['projectId'])) ? $data['projectId'] : '';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/projects/'.$id, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertEquals($id, $response['body']['$id']);
        $this->assertEquals('Project Test', $response['body']['name']);

        /**
         * Test for FAILURE
         */

        $response = $this->client->call(Client::METHOD_GET, '/projects/empty', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(404, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/projects/id-is-really-long-id-is-really-long-id-is-really-long-id-is-really-long', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(400, $response['headers']['status-code']);

         return $data;
    }

    /**
     * @depends testCreateProject
     */
    public function testGetProjectUsage($data):array
    {
        $id = (isset($data['projectId'])) ? $data['projectId'] : '';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/projects/'.$id.'/usage', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertArrayHasKey('collections', $response['body']);
        $this->assertArrayHasKey('documents', $response['body']);
        $this->assertArrayHasKey('network', $response['body']);
        $this->assertArrayHasKey('requests', $response['body']);
        $this->assertArrayHasKey('storage', $response['body']);
        $this->assertArrayHasKey('tasks', $response['body']);
        $this->assertArrayHasKey('users', $response['body']);
        $this->assertIsArray($response['body']['collections']['data']);
        $this->assertIsInt($response['body']['collections']['total']);
        $this->assertIsArray($response['body']['documents']['data']);
        $this->assertIsInt($response['body']['documents']['total']);
        $this->assertIsArray($response['body']['network']['data']);
        $this->assertIsInt($response['body']['network']['total']);
        $this->assertIsArray($response['body']['requests']['data']);
        $this->assertIsInt($response['body']['requests']['total']);
        $this->assertIsInt($response['body']['storage']['total']);
        $this->assertIsArray($response['body']['tasks']['data']);
        $this->assertIsInt($response['body']['tasks']['total']);
        $this->assertIsArray($response['body']['users']['data']);
        $this->assertIsInt($response['body']['users']['total']);

        /**
         * Test for FAILURE
         */

        $response = $this->client->call(Client::METHOD_GET, '/projects/empty', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(404, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/projects/id-is-really-long-id-is-really-long-id-is-really-long-id-is-really-long', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(400, $response['headers']['status-code']);

         return $data;
    }

    /**
     * @depends testGetProjectUsage
     */
    public function testUpdateProject($data):array
    {
        $id = (isset($data['projectId'])) ? $data['projectId'] : '';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/projects/'.$id, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Project Test 2',
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals('Project Test 2', $response['body']['name']);
        // $this->assertArrayHasKey('platforms', $response['body']); // TODO enable when response model is enabled
        // $this->assertArrayHasKey('webhooks', $response['body']); // TODO enable when response model is enabled
        // $this->assertArrayHasKey('keys', $response['body']); // TODO enable when response model is enabled
        // $this->assertArrayHasKey('tasks', $response['body']); // TODO enable when response model is enabled

        $projectId = $response['body']['$id'];

        /**
         * Test for FAILURE
         */
        
        $response = $this->client->call(Client::METHOD_POST, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => '',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        return ['projectId' => $projectId];
    }

    /**
     * @depends testGetProjectUsage
     */
    public function testUpdateProjectOAuth($data):array
    {
        $id = (isset($data['projectId'])) ? $data['projectId'] : '';
        $providers = require('app/config/providers.php');

        /**
         * Test for SUCCESS
         */

        foreach($providers as $key => $provider) {
            $response = $this->client->call(Client::METHOD_PATCH, '/projects/'.$id.'/oauth2', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()), [
                'provider' => $key,
                'appId' => 'AppId-'.ucfirst($key),
                'secret' => 'Secret-'.ucfirst($key),
            ]);
    
            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertNotEmpty($response['body']['$id']);
        }

        $response = $this->client->call(Client::METHOD_GET, '/projects/'.$id, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertEquals($id, $response['body']['$id']);

        foreach($providers as $key => $provider) {
            $this->assertEquals('AppId-'.ucfirst($key), $response['body']['usersOauth2'.ucfirst($key).'Appid']);
            $this->assertEquals('Secret-'.ucfirst($key), $response['body']['usersOauth2'.ucfirst($key).'Secret']);
        }

        /**
         * Test for FAILURE
         */
        
        $response = $this->client->call(Client::METHOD_PATCH, '/projects/'.$id.'/oauth2', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'provider' => 'unknown',
            'appId' => 'AppId',
            'secret' => 'Secret',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        return $data;
    }

    /**
     * @depends testCreateProject
     */
    public function testCreateProjectWebhook($data): array
    {
        $id = (isset($data['projectId'])) ? $data['projectId'] : '';

        $response = $this->client->call(Client::METHOD_POST, '/projects/'.$id.'/webhooks', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Webhook Test',
            'events' => ['account.create', 'account.update.email'],
            'url' => 'https://appwrite.io',
            'security' => true,
            'httpUser' => 'username',
            'httpPass' => 'password',
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertContains('account.create', $response['body']['events']);
        $this->assertContains('account.update.email', $response['body']['events']);
        $this->assertCount(2, $response['body']['events']);
        $this->assertEquals('https://appwrite.io', $response['body']['url']);
        $this->assertEquals('username', $response['body']['httpUser']);
        
        $data = array_merge($data, ['webhookId' => $response['body']['$id']]);

        /**
         * Test for FAILURE
         */
        
        // $response = $this->client->call(Client::METHOD_POST, '/projects', array_merge([
        //     'content-type' => 'application/json',
        //     'x-appwrite-project' => $this->getProject()['$id'],
        // ], $this->getHeaders()), [
        //     'name' => 'Project Test',
        // ]);

        // $this->assertEquals(400, $response['headers']['status-code']);

        return $data;
    }
}