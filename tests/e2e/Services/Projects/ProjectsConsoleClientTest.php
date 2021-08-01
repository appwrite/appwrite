<?php

namespace Tests\E2E\Services\Projects;

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
        $id = $data['projectId'] ?? '';

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
        $id = $data['projectId'] ?? '';

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
        $id = $data['projectId'] ?? '';

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
        $id = $data['projectId'] ?? '';

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
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        return ['projectId' => $projectId];
    }

    /**
     * @depends testGetProjectUsage
     */
    public function testUpdateProjectOAuth($data):array
    {
        $id = $data['projectId'] ?? '';
        $providers = require('app/config/providers.php');

        /**
         * Test for SUCCESS
         */

        foreach ($providers as $key => $provider) {
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

        foreach ($providers as $key => $provider) {
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
     * @depends testGetProjectUsage
     */
    public function testUpdateProjectAuthStatus($data):array
    {
        $id = $data['projectId'] ?? '';
        $auth = require('app/config/auth.php');
        
        $originalEmail = uniqid().'user@localhost.test';
        $originalPassword = 'password';
        $originalName = 'User Name';
        
        $response = $this->client->call(Client::METHOD_POST, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $id,
        ]), [
            'email' => $originalEmail,
            'password' => $originalPassword,
            'name' => $originalName,
        ]);

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $id,
        ]), [
            'email' => $originalEmail,
            'password' => $originalPassword,
        ]);

        $session = $this->client->parseCookie((string)$response['headers']['set-cookie'])['a_session_'.$id];

        /**
         * Test for SUCCESS
         */
        foreach ($auth as $index => $method) {
            $response = $this->client->call(Client::METHOD_PATCH, '/projects/'.$id.'/auth/'.$index, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()), [
                'status' => false,
            ]);
    
            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertNotEmpty($response['body']['$id']);

            $response = $this->client->call(Client::METHOD_GET, '/projects/'.$id, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));

            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertNotEmpty($response['body']['$id']);
            $this->assertEquals(false, $response['body'][$method['key']]);
        }
        
        $email = uniqid().'user@localhost.test';
        $password = 'password';
        $name = 'User Name';

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_POST, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $id,
        ]), [
            'email' => $email,
            'password' => $password,
            'name' => $name,
        ]);

        $this->assertEquals($response['headers']['status-code'], 501);

        $response = $this->client->call(Client::METHOD_POST, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $id,
            'cookie' => 'a_session_'.$id.'='.$session,
        ]), [
            'name' => 'Arsenal'
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        $teamUid = $response['body']['$id'];

        $response = $this->client->call(Client::METHOD_POST, '/teams/'.$teamUid.'/memberships', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $id,
            'cookie' => 'a_session_'.$id.'=' . $session,
        ]), [
            'email' => $email,
            'name' => 'Friend User',
            'roles' => ['admin', 'editor'],
            'url' => 'http://localhost:5000/join-us#title'
        ]);

        $this->assertEquals($response['headers']['status-code'], 501);

        $response = $this->client->call(Client::METHOD_POST, '/account/jwt', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $id,
            'cookie' => 'a_session_'.$id.'=' . $session,
        ]));

        $this->assertEquals($response['headers']['status-code'], 501);

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $id,
        ]), [
            'email' => $originalEmail,
            'password' => $originalPassword,
        ]);
        
        $this->assertEquals($response['headers']['status-code'], 501);

        $response = $this->client->call(Client::METHOD_POST, '/account/anonymous', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $id,
        ]), []);

        $this->assertEquals($response['headers']['status-code'], 501);

        // Cleanup

        foreach ($auth as $index => $method) {
            $response = $this->client->call(Client::METHOD_PATCH, '/projects/'.$id.'/auth/'.$index, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()), [
                'status' => true,
            ]);
        }

        return $data;
    }

    /**
     * @depends testGetProjectUsage
     */
    public function testUpdateProjectAuthLimit($data):array
    {
        $id = $data['projectId'] ?? '';
        
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/projects/'.$id.'/auth/limit', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'limit' => 1,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        
        $email = uniqid().'user@localhost.test';
        $password = 'password';
        $name = 'User Name';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $id,
        ]), [
            'email' => $email,
            'password' => $password,
            'name' => $name,
        ]);

        $this->assertEquals($response['headers']['status-code'], 501);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/projects/'.$id.'/auth/limit', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'limit' => 0,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        
        $response = $this->client->call(Client::METHOD_POST, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $id,
        ]), [
            'email' => $email,
            'password' => $password,
            'name' => $name,
        ]);

        $this->assertEquals($response['headers']['status-code'], 201);

        return $data;
    }

    public function testUpdateProjectServiceStatusAdmin(): array
    {
        $team = $this->client->call(Client::METHOD_POST, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ]), [
            'name' => 'Project Test',
        ]);
        $this->assertEquals(201, $team['headers']['status-code']);
        $this->assertNotEmpty($team['body']['$id']);

        $project = $this->client->call(Client::METHOD_POST, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ]), [
            'name' => 'Project Test',
            'teamId' => $team['body']['$id'],
        ]);

        $this->assertEquals(201, $project['headers']['status-code']);
        $this->assertNotEmpty($project['body']['$id']);

        $id = $project['body']['$id'];
        $services = require('app/config/services.php');

        /**
         * Test for Disabled
         */
        foreach ($services as $service) {
            if(!$service['optional']) {
                continue;
            }
            
            $response = $this->client->call(Client::METHOD_PATCH, '/projects/'.$id.'/service', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            ]), [
                'service' => $service,
                'status' => false,
            ]);

            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertNotEmpty($response['body']['$id']);

            $response = $this->client->call(Client::METHOD_GET, '/projects/'.$id, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            ]));

            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertNotEmpty($response['body']['$id']);
            $this->assertEquals(false, $response['body']['serviceStatusFor'.ucfirst($service)]);
        }
        
        /**
         * Admin request must succeed
         */
        
        $response = $this->client->call(Client::METHOD_GET, '/functions', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            // 'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-project' => $id,
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-mode' => 'admin'
        ]));
        
        $this->assertEquals(200, $response['headers']['status-code']);

        foreach ($services as $service) {
            $response = $this->client->call(Client::METHOD_PATCH, '/projects/'.$id.'/service/', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()), [
                'service' => $service,
                'status' => true,
            ]);
        }

        return ['projectId' => $id];
    }

    /** @depends testUpdateProjectServiceStatusAdmin */
    public function testUpdateProjectServiceStatus($data):void
    {
        $id = $data['projectId'];

        $services = ['functions', 'webhooks', 'avatars', 'health', 'locale', 'storage', 'teams'];

        /**
         * Test for Disabled
         */
        foreach ($services as $service) {
            $response = $this->client->call(Client::METHOD_PATCH, '/projects/'.$id.'/service', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            ]), [
                'service' => $service,
                'status' => false,
            ]);

            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertNotEmpty($response['body']['$id']);

            $response = $this->client->call(Client::METHOD_GET, '/projects/'.$id, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            ]));

            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertNotEmpty($response['body']['$id']);
            $this->assertEquals(false, $response['body']['serviceStatusFor'.ucfirst($service)]);
        }

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/functions', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $id,
        ], $this->getHeaders()));

        $this->assertEquals(503, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $id,
        ]), [
            'name' => 'Arsenal'
        ]);

        $this->assertEquals(503, $response['headers']['status-code']);

        // Cleanup

        foreach ($services as $service) {
            $response = $this->client->call(Client::METHOD_PATCH, '/projects/'.$id.'/service/', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()), [
                'service' => $service,
                'status' => true,
            ]);
        }
    }

    /**
     * @depends testCreateProject
     */
    public function testCreateProjectWebhook($data): array
    {
        $id = $data['projectId'] ?? '';

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
        $this->assertIsBool($response['body']['security']);
        $this->assertEquals(true, $response['body']['security']);
        $this->assertEquals('username', $response['body']['httpUser']);
        
        $data = array_merge($data, ['webhookId' => $response['body']['$id']]);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_POST, '/projects/'.$id.'/webhooks', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Webhook Test',
            'events' => ['account.unknown', 'account.update.email'],
            'url' => 'https://appwrite.io',
            'security' => true,
            'httpUser' => 'username',
            'httpPass' => 'password',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        return $data;
    }

    /**
     * @depends testCreateProjectWebhook
     */
    public function testListProjectWebhook($data): array
    {
        $id = $data['projectId'] ?? '';

        $response = $this->client->call(Client::METHOD_GET, '/projects/'.$id.'/webhooks', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(1, $response['body']['sum']);
        
        /**
         * Test for FAILURE
         */

        return $data;
    }

    /**
     * @depends testCreateProjectWebhook
     */
    public function testGetProjectWebhook($data): array
    {
        $id = $data['projectId'] ?? '';
        $webhookId = $data['webhookId'] ?? '';

        $response = $this->client->call(Client::METHOD_GET, '/projects/'.$id.'/webhooks/'.$webhookId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals($webhookId, $response['body']['$id']);
        $this->assertContains('account.create', $response['body']['events']);
        $this->assertContains('account.update.email', $response['body']['events']);
        $this->assertCount(2, $response['body']['events']);
        $this->assertEquals('https://appwrite.io', $response['body']['url']);
        $this->assertEquals('username', $response['body']['httpUser']);
        $this->assertEquals('password', $response['body']['httpPass']);
        
        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/projects/'.$id.'/webhooks/error', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(404, $response['headers']['status-code']);

        return $data;
    }

    /**
     * @depends testCreateProjectWebhook
     */
    public function testUpdateProjectWebhook($data): array
    {
        $id = $data['projectId'] ?? '';
        $webhookId = $data['webhookId'] ?? '';

        $response = $this->client->call(Client::METHOD_PUT, '/projects/'.$id.'/webhooks/'.$webhookId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Webhook Test Update',
            'events' => ['account.delete', 'account.sessions.delete', 'storage.files.create'],
            'url' => 'https://appwrite.io/new',
            'security' => false,
            'httpUser' => '',
            'httpPass' => '',
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals($webhookId, $response['body']['$id']);
        $this->assertEquals('Webhook Test Update', $response['body']['name']);
        $this->assertContains('account.delete', $response['body']['events']);
        $this->assertContains('account.sessions.delete', $response['body']['events']);
        $this->assertContains('storage.files.create', $response['body']['events']);
        $this->assertCount(3, $response['body']['events']);
        $this->assertEquals('https://appwrite.io/new', $response['body']['url']);
        $this->assertIsBool($response['body']['security']);
        $this->assertEquals(false, $response['body']['security']);
        $this->assertEquals('', $response['body']['httpUser']);
        $this->assertEquals('', $response['body']['httpPass']);

        $response = $this->client->call(Client::METHOD_GET, '/projects/'.$id.'/webhooks/'.$webhookId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals($webhookId, $response['body']['$id']);
        $this->assertEquals('Webhook Test Update', $response['body']['name']);
        $this->assertContains('account.delete', $response['body']['events']);
        $this->assertContains('account.sessions.delete', $response['body']['events']);
        $this->assertContains('storage.files.create', $response['body']['events']);
        $this->assertCount(3, $response['body']['events']);
        $this->assertEquals('https://appwrite.io/new', $response['body']['url']);
        $this->assertIsBool($response['body']['security']);
        $this->assertEquals(false, $response['body']['security']);
        $this->assertEquals('', $response['body']['httpUser']);
        $this->assertEquals('', $response['body']['httpPass']);
        
        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_PUT, '/projects/'.$id.'/webhooks/'.$webhookId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Webhook Test Update',
            'events' => ['account.delete', 'account.sessions.delete', 'storage.files.create', 'unknown'],
            'url' => 'https://appwrite.io/new',
            'security' => false,
            'httpUser' => '',
            'httpPass' => '',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_PUT, '/projects/'.$id.'/webhooks/'.$webhookId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Webhook Test Update',
            'events' => ['account.delete', 'account.sessions.delete', 'storage.files.create'],
            'url' => 'appwrite.io/new',
            'security' => false,
            'httpUser' => '',
            'httpPass' => '',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        return $data;
    }

    /**
     * @depends testCreateProjectWebhook
     */
    public function testDeleteProjectWebhook($data): array
    {
        $id = $data['projectId'] ?? '';
        $webhookId = $data['webhookId'] ?? '';

        $response = $this->client->call(Client::METHOD_DELETE, '/projects/'.$id.'/webhooks/'.$webhookId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(204, $response['headers']['status-code']);
        $this->assertEmpty($response['body']);

        $response = $this->client->call(Client::METHOD_GET, '/projects/'.$id.'/webhooks/'.$webhookId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(404, $response['headers']['status-code']);
        
        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_DELETE, '/projects/'.$id.'/webhooks/error', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(404, $response['headers']['status-code']);

        return $data;
    }

    // Keys

    /**
     * @depends testCreateProject
     */
    public function testCreateProjectKey($data): array
    {
        $id = $data['projectId'] ?? '';

        $response = $this->client->call(Client::METHOD_POST, '/projects/'.$id.'/keys', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Key Test',
            'scopes' => ['teams.read', 'teams.write'],
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals('Key Test', $response['body']['name']);
        $this->assertContains('teams.read', $response['body']['scopes']);
        $this->assertContains('teams.write', $response['body']['scopes']);
        $this->assertNotEmpty($response['body']['secret']);
        
        $data = array_merge($data, ['keyId' => $response['body']['$id']]);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_POST, '/projects/'.$id.'/keys', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Key Test',
            'scopes' => ['unknown'],
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        return $data;
    }

    /**
     * @depends testCreateProjectKey
     */
    public function testListProjectKey($data): array
    {
        $id = $data['projectId'] ?? '';

        $response = $this->client->call(Client::METHOD_GET, '/projects/'.$id.'/keys', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(1, $response['body']['sum']);
        
        /**
         * Test for FAILURE
         */

        return $data;
    }

    /**
     * @depends testCreateProjectKey
     */
    public function testGetProjectKey($data): array
    {
        $id = $data['projectId'] ?? '';
        $keyId = $data['keyId'] ?? '';

        $response = $this->client->call(Client::METHOD_GET, '/projects/'.$id.'/keys/'.$keyId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals($keyId, $response['body']['$id']);
        $this->assertEquals('Key Test', $response['body']['name']);
        $this->assertContains('teams.read', $response['body']['scopes']);
        $this->assertContains('teams.write', $response['body']['scopes']);
        $this->assertCount(2, $response['body']['scopes']);
        $this->assertNotEmpty($response['body']['secret']);
        
        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/projects/'.$id.'/keys/error', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(404, $response['headers']['status-code']);

        return $data;
    }

    /**
     * @depends testCreateProjectKey
     */
    public function testUpdateProjectKey($data): array
    {
        $id = $data['projectId'] ?? '';
        $keyId = $data['keyId'] ?? '';

        $response = $this->client->call(Client::METHOD_PUT, '/projects/'.$id.'/keys/'.$keyId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Key Test Update',
            'scopes' => ['users.read', 'users.write', 'collections.read'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals($keyId, $response['body']['$id']);
        $this->assertEquals('Key Test Update', $response['body']['name']);
        $this->assertContains('users.read', $response['body']['scopes']);
        $this->assertContains('users.write', $response['body']['scopes']);
        $this->assertContains('collections.read', $response['body']['scopes']);
        $this->assertCount(3, $response['body']['scopes']);

        $response = $this->client->call(Client::METHOD_GET, '/projects/'.$id.'/keys/'.$keyId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals($keyId, $response['body']['$id']);
        $this->assertEquals('Key Test Update', $response['body']['name']);
        $this->assertContains('users.read', $response['body']['scopes']);
        $this->assertContains('users.write', $response['body']['scopes']);
        $this->assertContains('collections.read', $response['body']['scopes']);
        $this->assertCount(3, $response['body']['scopes']);
        
        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_PUT, '/projects/'.$id.'/keys/'.$keyId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Key Test Update',
            'scopes' => ['users.read', 'users.write', 'collections.read', 'unknown'],
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        return $data;
    }

    /**
     * @depends testCreateProjectKey
     */
    public function testDeleteProjectKey($data): array
    {
        $id = $data['projectId'] ?? '';
        $keyId = $data['keyId'] ?? '';

        $response = $this->client->call(Client::METHOD_DELETE, '/projects/'.$id.'/keys/'.$keyId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(204, $response['headers']['status-code']);
        $this->assertEmpty($response['body']);

        $response = $this->client->call(Client::METHOD_GET, '/projects/'.$id.'/keys/'.$keyId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(404, $response['headers']['status-code']);
        
        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_DELETE, '/projects/'.$id.'/keys/error', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(404, $response['headers']['status-code']);

        return $data;
    }

    // Tasks

    /**
     * @depends testCreateProject
     */
    public function testCreateProjectTask($data): array
    {
        $id = $data['projectId'] ?? '';

        $response = $this->client->call(Client::METHOD_POST, '/projects/'.$id.'/tasks', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Task Test',
            'status' => 'play',
            'schedule' => '* * * * *',
            'security' => true,
            'httpMethod' => 'GET',
            'httpUrl' => 'http://example.com',
            'httpHeaders' => ['demo:value'],
            'httpUser' => 'username',
            'httpPass' => 'password',
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals('Task Test', $response['body']['name']);
        $this->assertEquals('play', $response['body']['status']);
        $this->assertEquals(true, $response['body']['security']);
        $this->assertEquals('* * * * *', $response['body']['schedule']);
        $this->assertEquals('GET', $response['body']['httpMethod']);
        $this->assertEquals('http://example.com', $response['body']['httpUrl']);
        $this->assertContains('demo:value', $response['body']['httpHeaders']);
        $this->assertCount(1, $response['body']['httpHeaders']);
        $this->assertEquals('username', $response['body']['httpUser']);
        $this->assertEquals('password', $response['body']['httpPass']);
        
        $data = array_merge($data, ['taskId' => $response['body']['$id']]);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_POST, '/projects/'.$id.'/tasks', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Task Test',
            'status' => 'unknown',
            'schedule' => '* * * * *',
            'security' => true,
            'httpMethod' => 'GET',
            'httpUrl' => 'http://example.com',
            'httpHeaders' => ['demo:value'],
            'httpUser' => 'username',
            'httpPass' => 'password',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/projects/'.$id.'/tasks', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Task Test',
            'status' => 'play',
            'schedule' => 'unknown',
            'security' => true,
            'httpMethod' => 'GET',
            'httpUrl' => 'http://example.com',
            'httpHeaders' => ['demo:value'],
            'httpUser' => 'username',
            'httpPass' => 'password',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
        
        $response = $this->client->call(Client::METHOD_POST, '/projects/'.$id.'/tasks', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Task Test',
            'status' => 'play',
            'schedule' => '* * * * *',
            'security' => 'string',
            'httpMethod' => 'GET',
            'httpUrl' => 'http://example.com',
            'httpHeaders' => ['demo:value'],
            'httpUser' => 'username',
            'httpPass' => 'password',
        ]);
            
        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/projects/'.$id.'/tasks', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Task Test',
            'status' => 'play',
            'schedule' => '* * * * *',
            'security' => true,
            'httpMethod' => 'UNKNOWN',
            'httpUrl' => 'http://example.com',
            'httpHeaders' => ['demo:value'],
            'httpUser' => 'username',
            'httpPass' => 'password',
        ]);
            
        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/projects/'.$id.'/tasks', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Task Test',
            'status' => 'play',
            'schedule' => '* * * * *',
            'security' => true,
            'httpMethod' => 'GET',
            'httpUrl' => 'http://example.com',
            'httpHeaders' => 'string',
            'httpUser' => 'username',
            'httpPass' => 'password',
        ]);
            
        $this->assertEquals(400, $response['headers']['status-code']);

        return $data;
    }

    /**
     * @depends testCreateProjectTask
     */
    public function testListProjectTask($data): array
    {
        $id = $data['projectId'] ?? '';

        $response = $this->client->call(Client::METHOD_GET, '/projects/'.$id.'/tasks', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(1, $response['body']['sum']);
        
        /**
         * Test for FAILURE
         */

        return $data;
    }

    /**
     * @depends testCreateProjectTask
     */
    public function testGetProjectTask($data): array
    {
        $id = $data['projectId'] ?? '';
        $taskId = $data['taskId'] ?? '';

        $response = $this->client->call(Client::METHOD_GET, '/projects/'.$id.'/tasks/'.$taskId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals($taskId, $response['body']['$id']);
        $this->assertEquals('Task Test', $response['body']['name']);
        $this->assertEquals('play', $response['body']['status']);
        $this->assertEquals(true, $response['body']['security']);
        $this->assertEquals('* * * * *', $response['body']['schedule']);
        $this->assertEquals('GET', $response['body']['httpMethod']);
        $this->assertEquals('http://example.com', $response['body']['httpUrl']);
        $this->assertContains('demo:value', $response['body']['httpHeaders']);
        $this->assertCount(1, $response['body']['httpHeaders']);
        $this->assertEquals('username', $response['body']['httpUser']);
        $this->assertEquals('password', $response['body']['httpPass']);
        
        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/projects/'.$id.'/tasks/error', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(404, $response['headers']['status-code']);

        return $data;
    }

    /**
     * @depends testCreateProjectTask
     */
    public function testUpdateProjectTask($data): array
    {
        $id = $data['projectId'] ?? '';
        $taskId = $data['taskId'] ?? '';

        $response = $this->client->call(Client::METHOD_PUT, '/projects/'.$id.'/tasks/'.$taskId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Task Test 2',
            'status' => 'pause',
            'schedule' => '*/5 * * * *',
            'security' => false,
            'httpMethod' => 'POST',
            'httpUrl' => 'http://example.com/demo',
            'httpHeaders' => ['demo1: value1', 'demo2:value2'],
            'httpUser' => 'username1',
            'httpPass' => 'password1',
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals($taskId, $response['body']['$id']);
        $this->assertEquals('Task Test 2', $response['body']['name']);
        $this->assertEquals('pause', $response['body']['status']);
        $this->assertEquals(false, $response['body']['security']);
        $this->assertEquals('*/5 * * * *', $response['body']['schedule']);
        $this->assertEquals('POST', $response['body']['httpMethod']);
        $this->assertEquals('http://example.com/demo', $response['body']['httpUrl']);
        $this->assertContains('demo1: value1', $response['body']['httpHeaders']);
        $this->assertContains('demo2:value2', $response['body']['httpHeaders']);
        $this->assertCount(2, $response['body']['httpHeaders']);
        $this->assertEquals('username1', $response['body']['httpUser']);
        $this->assertEquals('password1', $response['body']['httpPass']);

        $response = $this->client->call(Client::METHOD_GET, '/projects/'.$id.'/tasks/'.$taskId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals($taskId, $response['body']['$id']);
        $this->assertEquals('Task Test 2', $response['body']['name']);
        $this->assertEquals('pause', $response['body']['status']);
        $this->assertEquals(false, $response['body']['security']);
        $this->assertEquals('*/5 * * * *', $response['body']['schedule']);
        $this->assertEquals('POST', $response['body']['httpMethod']);
        $this->assertEquals('http://example.com/demo', $response['body']['httpUrl']);
        $this->assertContains('demo1: value1', $response['body']['httpHeaders']);
        $this->assertContains('demo2:value2', $response['body']['httpHeaders']);
        $this->assertCount(2, $response['body']['httpHeaders']);
        $this->assertEquals('username1', $response['body']['httpUser']);
        $this->assertEquals('password1', $response['body']['httpPass']);
        
        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_PUT, '/projects/'.$id.'/tasks/'.$taskId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Task Test 2',
            'status' => 'pause1',
            'schedule' => '* * * * *',
            'security' => false,
            'httpMethod' => 'POST',
            'httpUrl' => 'http://example.com/demo',
            'httpHeaders' => ['demo1: value1', 'demo2:value2'],
            'httpUser' => 'username1',
            'httpPass' => 'password1',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_PUT, '/projects/'.$id.'/tasks/'.$taskId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Task Test 2',
            'status' => 'pause',
            'schedule' => '* * * * *xxx',
            'security' => false,
            'httpMethod' => 'POST',
            'httpUrl' => 'http://example.com/demo',
            'httpHeaders' => ['demo1: value1', 'demo2:value2'],
            'httpUser' => 'username1',
            'httpPass' => 'password1',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_PUT, '/projects/'.$id.'/tasks/'.$taskId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Task Test 2',
            'status' => 'pause',
            'schedule' => '* * * * *',
            'security' => 'string',
            'httpMethod' => 'POST',
            'httpUrl' => 'http://example.com/demo',
            'httpHeaders' => ['demo1: value1', 'demo2:value2'],
            'httpUser' => 'username1',
            'httpPass' => 'password1',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_PUT, '/projects/'.$id.'/tasks/'.$taskId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Task Test 2',
            'status' => 'pause',
            'schedule' => '* * * * *',
            'security' => false,
            'httpMethod' => 'UNKNOWN',
            'httpUrl' => 'http://example.com/demo',
            'httpHeaders' => ['demo1: value1', 'demo2:value2'],
            'httpUser' => 'username1',
            'httpPass' => 'password1',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_PUT, '/projects/'.$id.'/tasks/'.$taskId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Task Test 2',
            'status' => 'pause',
            'schedule' => '* * * * *',
            'security' => false,
            'httpMethod' => 'POST',
            'httpUrl' => 'example.com/demo',
            'httpHeaders' => ['demo1: value1', 'demo2:value2'],
            'httpUser' => 'username1',
            'httpPass' => 'password1',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_PUT, '/projects/'.$id.'/tasks/'.$taskId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Task Test 2',
            'status' => 'pause',
            'schedule' => '* * * * *',
            'security' => false,
            'httpMethod' => 'POST',
            'httpUrl' => 'http://example.com/demo',
            'httpHeaders' => 'string',
            'httpUser' => 'username1',
            'httpPass' => 'password1',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        return $data;
    }

    /**
     * @depends testCreateProjectTask
     */
    public function testDeleteProjectTask($data): array
    {
        $id = $data['projectId'] ?? '';
        $taskId = $data['taskId'] ?? '';

        $response = $this->client->call(Client::METHOD_DELETE, '/projects/'.$id.'/tasks/'.$taskId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(204, $response['headers']['status-code']);
        $this->assertEmpty($response['body']);

        $response = $this->client->call(Client::METHOD_GET, '/projects/'.$id.'/tasks/'.$taskId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(404, $response['headers']['status-code']);
        
        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_DELETE, '/projects/'.$id.'/tasks/error', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(404, $response['headers']['status-code']);

        return $data;
    }

    // Platforms

    /**
     * @depends testCreateProject
     */
    public function testCreateProjectPlatform($data): array
    {
        $id = $data['projectId'] ?? '';

        $response = $this->client->call(Client::METHOD_POST, '/projects/'.$id.'/platforms', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'type' => 'web',
            'name' => 'Web App',
            'key' => '',
            'store' => '',
            'hostname' => 'localhost',
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals('web', $response['body']['type']);
        $this->assertEquals('Web App', $response['body']['name']);
        $this->assertEquals('', $response['body']['key']);
        $this->assertEquals('', $response['body']['store']);
        $this->assertEquals('localhost', $response['body']['hostname']);
        
        $data = array_merge($data, ['platformWebId' => $response['body']['$id']]);

        $response = $this->client->call(Client::METHOD_POST, '/projects/'.$id.'/platforms', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'type' => 'flutter-ios',
            'name' => 'Flutter App (iOS)',
            'key' => 'com.example.ios',
            'store' => '',
            'hostname' => '',
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals('flutter-ios', $response['body']['type']);
        $this->assertEquals('Flutter App (iOS)', $response['body']['name']);
        $this->assertEquals('com.example.ios', $response['body']['key']);
        $this->assertEquals('', $response['body']['store']);
        $this->assertEquals('', $response['body']['hostname']);
        
        $data = array_merge($data, ['platformFultteriOSId' => $response['body']['$id']]);

        $response = $this->client->call(Client::METHOD_POST, '/projects/'.$id.'/platforms', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'type' => 'flutter-android',
            'name' => 'Flutter App (Android)',
            'key' => 'com.example.android',
            'store' => '',
            'hostname' => '',
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals('flutter-android', $response['body']['type']);
        $this->assertEquals('Flutter App (Android)', $response['body']['name']);
        $this->assertEquals('com.example.android', $response['body']['key']);
        $this->assertEquals('', $response['body']['store']);
        $this->assertEquals('', $response['body']['hostname']);
        
        $data = array_merge($data, ['platformFultterAndroidId' => $response['body']['$id']]);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_POST, '/projects/'.$id.'/platforms', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'type' => 'unknown',
            'name' => 'Web App',
            'key' => '',
            'store' => '',
            'hostname' => 'localhost',
        ]);
        
        $this->assertEquals(400, $response['headers']['status-code']);

        // $response = $this->client->call(Client::METHOD_POST, '/projects/'.$id.'/platforms', array_merge([
        //     'content-type' => 'application/json',
        //     'x-appwrite-project' => $this->getProject()['$id'],
        // ], $this->getHeaders()), [
        //     'type' => 'web',
        //     'name' => 'Web App',
        //     'key' => '',
        //     'store' => '',
        //     'hostname' => 'https://localhost',
        // ]);

        // $this->assertEquals(400, $response['headers']['status-code']);

        return $data;
    }

    /**
     * @depends testCreateProjectPlatform
     */
    public function testListProjectPlatform($data): array
    {
        $id = $data['projectId'] ?? '';
        
        $response = $this->client->call(Client::METHOD_GET, '/projects/'.$id.'/platforms', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(3, $response['body']['sum']);

        /**
         * Test for FAILURE
         */
 
        return $data;
    }

    /**
     * @depends testCreateProjectPlatform
     */
    public function testGetProjectPlatform($data): array
    {
        $id = $data['projectId'] ?? '';
        
        $platformWebId = $data['platformWebId'] ?? '';

        $response = $this->client->call(Client::METHOD_GET, '/projects/'.$id.'/platforms/'.$platformWebId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals($platformWebId, $response['body']['$id']);
        $this->assertEquals('web', $response['body']['type']);
        $this->assertEquals('Web App', $response['body']['name']);
        $this->assertEquals('', $response['body']['key']);
        $this->assertEquals('', $response['body']['store']);
        $this->assertEquals('localhost', $response['body']['hostname']);
         
        $platformFultteriOSId = $data['platformFultteriOSId'] ?? '';

        $response = $this->client->call(Client::METHOD_GET, '/projects/'.$id.'/platforms/'.$platformFultteriOSId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals($platformFultteriOSId, $response['body']['$id']);
        $this->assertEquals('flutter-ios', $response['body']['type']);
        $this->assertEquals('Flutter App (iOS)', $response['body']['name']);
        $this->assertEquals('com.example.ios', $response['body']['key']);
        $this->assertEquals('', $response['body']['store']);
        $this->assertEquals('', $response['body']['hostname']);
        
        $platformFultterAndroidId = $data['platformFultterAndroidId'] ?? '';

        $response = $this->client->call(Client::METHOD_GET, '/projects/'.$id.'/platforms/'.$platformFultterAndroidId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals($platformFultterAndroidId, $response['body']['$id']);
        $this->assertEquals('flutter-android', $response['body']['type']);
        $this->assertEquals('Flutter App (Android)', $response['body']['name']);
        $this->assertEquals('com.example.android', $response['body']['key']);
        $this->assertEquals('', $response['body']['store']);
        $this->assertEquals('', $response['body']['hostname']);
        
        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/projects/'.$id.'/platforms/error', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(404, $response['headers']['status-code']);

        return $data;
    }

    /**
     * @depends testCreateProjectPlatform
     */
    public function testUpdateProjectPlatform($data): array
    {
        $id = $data['projectId'] ?? '';

        $platformWebId = $data['platformWebId'] ?? '';

        $response = $this->client->call(Client::METHOD_PUT, '/projects/'.$id.'/platforms/'.$platformWebId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Web App 2',
            'key' => '',
            'store' => '',
            'hostname' => 'localhost-new',
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals($platformWebId, $response['body']['$id']);
        $this->assertEquals('web', $response['body']['type']);
        $this->assertEquals('Web App 2', $response['body']['name']);
        $this->assertEquals('', $response['body']['key']);
        $this->assertEquals('', $response['body']['store']);
        $this->assertEquals('localhost-new', $response['body']['hostname']);

        $platformFultteriOSId = $data['platformFultteriOSId'] ?? '';

        $response = $this->client->call(Client::METHOD_PUT, '/projects/'.$id.'/platforms/'.$platformFultteriOSId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Flutter App (iOS) 2',
            'key' => 'com.example.ios2',
            'store' => '',
            'hostname' => '',
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals($platformFultteriOSId, $response['body']['$id']);
        $this->assertEquals('flutter-ios', $response['body']['type']);
        $this->assertEquals('Flutter App (iOS) 2', $response['body']['name']);
        $this->assertEquals('com.example.ios2', $response['body']['key']);
        $this->assertEquals('', $response['body']['store']);
        $this->assertEquals('', $response['body']['hostname']);
       
        $platformFultterAndroidId = $data['platformFultterAndroidId'] ?? '';

        $response = $this->client->call(Client::METHOD_PUT, '/projects/'.$id.'/platforms/'.$platformFultterAndroidId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Flutter App (Android) 2',
            'key' => 'com.example.android2',
            'store' => '',
            'hostname' => '',
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals($platformFultterAndroidId, $response['body']['$id']);
        $this->assertEquals('flutter-android', $response['body']['type']);
        $this->assertEquals('Flutter App (Android) 2', $response['body']['name']);
        $this->assertEquals('com.example.android2', $response['body']['key']);
        $this->assertEquals('', $response['body']['store']);
        $this->assertEquals('', $response['body']['hostname']);

        /**
         * Test for FAILURE
         */

        return $data;
    }

    /**
     * @depends testCreateProjectPlatform
     */
    public function testDeleteProjectPlatform($data): array
    {
        $id = $data['projectId'] ?? '';
        
        $platformWebId = $data['platformWebId'] ?? '';

        $response = $this->client->call(Client::METHOD_DELETE, '/projects/'.$id.'/platforms/'.$platformWebId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(204, $response['headers']['status-code']);
        $this->assertEmpty($response['body']);

        $response = $this->client->call(Client::METHOD_GET, '/projects/'.$id.'/platforms/'.$platformWebId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(404, $response['headers']['status-code']);

        $platformFultteriOSId = $data['platformFultteriOSId'] ?? '';

        $response = $this->client->call(Client::METHOD_DELETE, '/projects/'.$id.'/platforms/'.$platformFultteriOSId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(204, $response['headers']['status-code']);
        $this->assertEmpty($response['body']);

        $response = $this->client->call(Client::METHOD_GET, '/projects/'.$id.'/platforms/'.$platformFultteriOSId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(404, $response['headers']['status-code']);

        $platformFultterAndroidId = $data['platformFultterAndroidId'] ?? '';

        $response = $this->client->call(Client::METHOD_DELETE, '/projects/'.$id.'/platforms/'.$platformFultterAndroidId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(204, $response['headers']['status-code']);
        $this->assertEmpty($response['body']);

        $response = $this->client->call(Client::METHOD_GET, '/projects/'.$id.'/platforms/'.$platformFultterAndroidId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(404, $response['headers']['status-code']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_DELETE, '/projects/'.$id.'/tasks/error', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(404, $response['headers']['status-code']);

        return $data;
    }

    // Domains

    /**
     * @depends testCreateProject
     */
    public function testCreateProjectDomain($data): array
    {
        $id = $data['projectId'] ?? '';

        $response = $this->client->call(Client::METHOD_POST, '/projects/'.$id.'/domains', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'domain' => 'sub.example.com',
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        // $this->assertIsInt($response['body']['updated']);
        $this->assertEquals('sub.example.com', $response['body']['domain']);
        $this->assertEquals('com', $response['body']['tld']);
        $this->assertEquals('example.com', $response['body']['registerable']);
        $this->assertEquals(false, $response['body']['verification']);
        
        $data = array_merge($data, ['domainId' => $response['body']['$id']]);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_POST, '/projects/'.$id.'/platforms', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'domain' => '123',
        ]);
        
        $this->assertEquals(400, $response['headers']['status-code']);

        return $data;
    }

    /**
     * @depends testCreateProjectDomain
     */
    public function testListProjectDomain($data): array
    {
        $id = $data['projectId'] ?? '';
        
        $response = $this->client->call(Client::METHOD_GET, '/projects/'.$id.'/domains', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(1, $response['body']['sum']);

        /**
         * Test for FAILURE
         */
 
        return $data;
    }

    /**
     * @depends testCreateProjectDomain
     */
    public function testGetProjectDomain($data): array
    {
        $id = $data['projectId'] ?? '';
        $domainId = $data['domainId'] ?? '';

        $response = $this->client->call(Client::METHOD_GET, '/projects/'.$id.'/domains/'.$domainId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals($domainId, $response['body']['$id']);
        // $this->assertIsInt($response['body']['updated']);
        $this->assertEquals('sub.example.com', $response['body']['domain']);
        $this->assertEquals('com', $response['body']['tld']);
        $this->assertEquals('example.com', $response['body']['registerable']);
        $this->assertEquals(false, $response['body']['verification']);
         
        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/projects/'.$id.'/domains/error', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(404, $response['headers']['status-code']);

        return $data;
    }

    /**
     * @depends testCreateProjectDomain
     */
    public function testUpdateProjectDomain($data): array
    {
        $id = $data['projectId'] ?? '';
        $domainId = $data['domainId'] ?? '';

        $response = $this->client->call(Client::METHOD_PATCH, '/projects/'.$id.'/domains/'.$domainId.'/verification', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(401, $response['headers']['status-code']);

        /**
         * Test for FAILURE
         */

        return $data;
    }

    /**
     * @depends testCreateProjectDomain
     */
    public function testDeleteProjectDomain($data): array
    {
        $id = $data['projectId'] ?? '';
        $domainId = $data['domainId'] ?? '';

        $response = $this->client->call(Client::METHOD_DELETE, '/projects/'.$id.'/domains/'.$domainId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(204, $response['headers']['status-code']);
        $this->assertEmpty($response['body']);

        $response = $this->client->call(Client::METHOD_GET, '/projects/'.$id.'/domains/'.$domainId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(404, $response['headers']['status-code']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_DELETE, '/projects/'.$id.'/domains/error', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(404, $response['headers']['status-code']);

        return $data;
    }
}
