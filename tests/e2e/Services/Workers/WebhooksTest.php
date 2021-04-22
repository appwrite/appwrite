<?php

namespace Tests\E2E\Services\Workers;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectConsole;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;
use Tests\E2E\Scopes\SideServer;

class WebhooksTest extends Scope
{
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
    public function testCreateWebhook($data): array
    {
        $id = (isset($data['projectId'])) ? $data['projectId'] : '';

        $response = $this->client->call(Client::METHOD_POST, '/projects/'.$id.'/webhooks', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Webhook Worker Test',
            'events' => ['account.create', 'account.update.email'],
            'url' => 'http://request-catcher:5000/webhook',
            'security' => true,
            'httpUser' => 'username',
            'httpPass' => 'password',
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertContains('account.create', $response['body']['events']);
        $this->assertContains('account.update.email', $response['body']['events']);
        $this->assertCount(2, $response['body']['events']);
        $this->assertEquals('http://request-catcher:5000/webhook', $response['body']['url']);
        $this->assertIsBool($response['body']['security']);
        $this->assertEquals(true, $response['body']['security']);
        $this->assertEquals('username', $response['body']['httpUser']);
        
        $data = array_merge($data, ['webhookId' => $response['body']['$id']]);

        /**
         * Test for FAILURE
         */
        
        return $data;
    }

    /**
     * @depends testCreateWebhook
     */
    public function testCreateAccount($data)
    {
        $projectId = (isset($data['projectId'])) ? $data['projectId'] : '';
        $email = uniqid().'webhook.user@localhost.test';
        $password = 'password';
        $name = 'User Name';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ]), [
            'email' => $email,
            'password' => $password,
            'name' => $name,
        ]);

        $this->assertEquals($response['headers']['status-code'], 201);

        $webhook = $this->getLastRequest();

        $this->assertNotEmpty($webhook['data']);
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertIsBool($webhook['data']['active']);
        $this->assertIsNumeric($webhook['data']['registration']);
        $this->assertEquals($webhook['data']['email'], $email);
        $this->assertEquals($webhook['data']['name'], $name);
        $this->assertIsBool($webhook['data']['emailVerification']);
        $this->assertIsArray($webhook['data']['prefs']);
    }
}