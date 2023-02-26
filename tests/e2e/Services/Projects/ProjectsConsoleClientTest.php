<?php

namespace Tests\E2E\Services\Projects;

use Appwrite\Auth\Auth;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\ProjectConsole;
use Tests\E2E\Scopes\SideClient;
use Tests\E2E\Services\Projects\ProjectsBase;
use Tests\E2E\Client;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Helpers\ID;

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
            'teamId' => ID::unique(),
            'name' => 'Project Test',
        ]);

        $this->assertEquals(201, $team['headers']['status-code']);
        $this->assertEquals('Project Test', $team['body']['name']);
        $this->assertNotEmpty($team['body']['$id']);

        $response = $this->client->call(Client::METHOD_POST, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'projectId' => ID::unique(),
            'name' => 'Project Test',
            'teamId' => $team['body']['$id'],
            'region' => 'default',
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals('Project Test', $response['body']['name']);
        $this->assertEquals($team['body']['$id'], $response['body']['teamId']);
        $this->assertArrayHasKey('platforms', $response['body']);
        $this->assertArrayHasKey('webhooks', $response['body']);
        $this->assertArrayHasKey('keys', $response['body']);

        $projectId = $response['body']['$id'];

        $response = $this->client->call(Client::METHOD_POST, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'projectId' => ID::unique(),
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

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_POST, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'projectId' => ID::unique(),
            'name' => '',
            'teamId' => $team['body']['$id'],
            'region' => 'default'
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'projectId' => ID::unique(),
            'name' => 'Project Test',
            'region' => 'default'
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        return ['projectId' => $projectId];
    }

    /**
     * @depends testCreateProject
     */
    public function testListProject($data): array
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
         * Test search queries
         */
        $response = $this->client->call(Client::METHOD_GET, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders(), [
            'search' => $id
        ]));

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertEquals($response['body']['total'], 2);
        $this->assertIsArray($response['body']['projects']);
        $this->assertCount(2, $response['body']['projects']);
        $this->assertEquals($response['body']['projects'][0]['name'], 'Project Test');

        $response = $this->client->call(Client::METHOD_GET, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders(), [
            'search' => 'Project Test'
        ]));

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertEquals($response['body']['total'], 2);
        $this->assertIsArray($response['body']['projects']);
        $this->assertCount(2, $response['body']['projects']);
        $this->assertEquals($response['body']['projects'][0]['$id'], $data['projectId']);

        /**
         * Test pagination
         */
        $team = $this->client->call(Client::METHOD_POST, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'teamId' => ID::unique(),
            'name' => 'Project Test 2',
        ]);

        $this->assertEquals(201, $team['headers']['status-code']);
        $this->assertEquals('Project Test 2', $team['body']['name']);
        $this->assertNotEmpty($team['body']['$id']);

        $response = $this->client->call(Client::METHOD_POST, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'projectId' => ID::unique(),
            'name' => 'Project Test 2',
            'teamId' => $team['body']['$id'],
            'region' => 'default'
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals('Project Test 2', $response['body']['name']);
        $this->assertEquals($team['body']['$id'], $response['body']['teamId']);
        $this->assertArrayHasKey('platforms', $response['body']);
        $this->assertArrayHasKey('webhooks', $response['body']);
        $this->assertArrayHasKey('keys', $response['body']);

        $response = $this->client->call(Client::METHOD_GET, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [ 'equal("teamId", "' . $team['body']['$id'] . '")' ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertCount(1, $response['body']['projects']);
        $this->assertEquals($team['body']['$id'], $response['body']['projects'][0]['teamId']);

        $response = $this->client->call(Client::METHOD_GET, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [ 'limit(1)' ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertCount(1, $response['body']['projects']);
        $this->assertEquals('Project Test', $response['body']['projects'][0]['name']);

        $response = $this->client->call(Client::METHOD_GET, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [ 'offset(2)' ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertCount(1, $response['body']['projects']);
        $this->assertEquals('Project Test 2', $response['body']['projects'][0]['name']);

        $response = $this->client->call(Client::METHOD_GET, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [ 'equal("name", "Project Test 2")' ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertCount(1, $response['body']['projects']);
        $this->assertEquals('Project Test 2', $response['body']['projects'][0]['name']);

        $response = $this->client->call(Client::METHOD_GET, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [ 'orderDesc("")' ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertCount(3, $response['body']['projects']);
        $this->assertEquals('Project Test 2', $response['body']['projects'][0]['name']);
        $this->assertEquals('Project Test', $response['body']['projects'][1]['name']);

        $response = $this->client->call(Client::METHOD_GET, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertCount(3, $response['body']['projects']);
        $this->assertEquals('Project Test', $response['body']['projects'][0]['name']);
        $this->assertEquals('Project Test 2', $response['body']['projects'][2]['name']);

        $response = $this->client->call(Client::METHOD_GET, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [ 'cursorAfter("' . $response['body']['projects'][0]['$id'] . '")' ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertCount(2, $response['body']['projects']);
        $this->assertEquals('Project Test 2', $response['body']['projects'][1]['name']);

        $response = $this->client->call(Client::METHOD_GET, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [ 'cursorBefore("' . $response['body']['projects'][0]['$id'] . '")' ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertCount(1, $response['body']['projects']);
        $this->assertEquals('Project Test', $response['body']['projects'][0]['name']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [ 'cursorAfter("unknown")' ],
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        return $data;
    }

    /**
     * @depends testCreateProject
     */
    public function testGetProject($data): array
    {
        $id = $data['projectId'] ?? '';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id, array_merge([
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
    public function testGetProjectUsage($data): array
    {
        $id = $data['projectId'] ?? '';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id . '/usage', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(count($response['body']), 9);
        $this->assertNotEmpty($response['body']);
        $this->assertEquals('30d', $response['body']['range']);
        $this->assertIsArray($response['body']['requests']);
        $this->assertIsArray($response['body']['network']);
        $this->assertIsArray($response['body']['executions']);
        $this->assertIsArray($response['body']['documents']);
        $this->assertIsArray($response['body']['databases']);
        $this->assertIsArray($response['body']['buckets']);
        $this->assertIsArray($response['body']['users']);
        $this->assertIsArray($response['body']['storage']);

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
    public function testUpdateProject($data): array
    {
        $id = $data['projectId'] ?? '';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $id, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'projectId' => ID::unique(),
            'name' => 'Project Test 2',
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals('Project Test 2', $response['body']['name']);
        $this->assertArrayHasKey('platforms', $response['body']);
        $this->assertArrayHasKey('webhooks', $response['body']);
        $this->assertArrayHasKey('keys', $response['body']);

        $projectId = $response['body']['$id'];

        /**
         * Test for FAILURE
         */

        $response = $this->client->call(Client::METHOD_POST, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'projectId' => ID::unique(),
            'name' => '',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        return ['projectId' => $projectId];
    }

    /** @depends testGetProjectUsage */
    public function testUpdateProjectAuthDuration($data): array
    {
        $id = $data['projectId'];

        // Check defaults
        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(Auth::TOKEN_EXPIRATION_LOGIN_LONG, $response['body']['authDuration']); // 1 Year

        /**
         * Test for SUCCESS
         */

        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $id . '/auth/duration', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'duration' => 60, // Set session duration to 2 minutes
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals('Project Test 2', $response['body']['name']);
        $this->assertArrayHasKey('platforms', $response['body']);
        $this->assertArrayHasKey('webhooks', $response['body']);
        $this->assertArrayHasKey('keys', $response['body']);
        $this->assertEquals(60, $response['body']['authDuration']);

        $projectId = $response['body']['$id'];

        // Create New User
        $response = $this->client->call(Client::METHOD_POST, '/account', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'userId' => 'unique()',
            'email' => 'test' . rand(0, 9999) . '@example.com',
            'password' => 'password',
            'name' => 'Test User',
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        $userEmail = $response['body']['email'];

        // Create New User Session
        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ]), [
            'email' => $userEmail,
            'password' => 'password',
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        $sessionCookie = $response['headers']['set-cookie'];

        // Test for SUCCESS
        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'Cookie' => $sessionCookie,
        ]));

        $this->assertEquals(200, $response['headers']['status-code']);

        // Check session doesn't expire too soon.

        sleep(30);

        // Get User
        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'Cookie' => $sessionCookie,
        ]));

        $this->assertEquals(200, $response['headers']['status-code']);

        // Wait just over a minute
        sleep(35);

        // Get User
        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'Cookie' => $sessionCookie,
        ]));

        $this->assertEquals(401, $response['headers']['status-code']);

        // Return project back to normal
        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $id . '/auth/duration', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'duration' => Auth::TOKEN_EXPIRATION_LOGIN_LONG,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $projectId = $response['body']['$id'];

        // Check project is back to normal
        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $projectId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(Auth::TOKEN_EXPIRATION_LOGIN_LONG, $response['body']['authDuration']); // 1 Year

        return ['projectId' => $projectId];
    }

    /**
     * @depends testGetProjectUsage
     */
    public function testUpdateProjectOAuth($data): array
    {
        $id = $data['projectId'] ?? '';
        $providers = require('app/config/providers.php');

        /**
         * Test for SUCCESS
         */

        foreach ($providers as $key => $provider) {
            $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $id . '/oauth2', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()), [
                'provider' => $key,
                'appId' => 'AppId-' . ucfirst($key),
                'secret' => 'Secret-' . ucfirst($key),
            ]);

            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertNotEmpty($response['body']['$id']);
        }

        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertEquals($id, $response['body']['$id']);

        foreach ($providers as $key => $provider) {
            $asserted = false;
            foreach ($response['body']['providers'] as $responseProvider) {
                if ($responseProvider['name'] === ucfirst($key)) {
                    $this->assertEquals('AppId-' . ucfirst($key), $responseProvider['appId']);
                    $this->assertEquals('Secret-' . ucfirst($key), $responseProvider['secret']);
                    $this->assertFalse($responseProvider['enabled']);
                    $asserted = true;
                    break;
                }
            }

            $this->assertTrue($asserted);
        }

        // Enable providers
        $i = 0;
        foreach ($providers as $key => $provider) {
            $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $id . '/oauth2', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()), [
                'provider' => $key,
                'enabled' => $i === 0 ? false : true // On first provider, test enabled=false
            ]);

            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertNotEmpty($response['body']['$id']);

            $i++;
        }

        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertEquals($id, $response['body']['$id']);

        $i = 0;
        foreach ($providers as $key => $provider) {
            $asserted = false;
            foreach ($response['body']['providers'] as $responseProvider) {
                if ($responseProvider['name'] === ucfirst($key)) {
                    // On first provider, test enabled=false
                    $this->assertEquals($i !== 0, $responseProvider['enabled']);
                    $asserted = true;
                    break;
                }
            }

            $this->assertTrue($asserted);

            $i++;
        }

        /**
         * Test for FAILURE
         */

        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $id . '/oauth2', array_merge([
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
    public function testUpdateProjectAuthStatus($data): array
    {
        $id = $data['projectId'] ?? '';
        $auth = require('app/config/auth.php');

        $originalEmail = uniqid() . 'user@localhost.test';
        $originalPassword = 'password';
        $originalName = 'User Name';

        $response = $this->client->call(Client::METHOD_POST, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $id,
        ]), [
            'userId' => ID::unique(),
            'email' => $originalEmail,
            'password' => $originalPassword,
            'name' => $originalName,
        ]);

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $id,
        ]), [
            'email' => $originalEmail,
            'password' => $originalPassword,
        ]);

        $session = $this->client->parseCookie((string)$response['headers']['set-cookie'])['a_session_' . $id];

        /**
         * Test for SUCCESS
         */
        foreach ($auth as $index => $method) {
            $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $id . '/auth/' . $index, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()), [
                'status' => false,
            ]);

            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertNotEmpty($response['body']['$id']);

            $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));

            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertNotEmpty($response['body']['$id']);
            $this->assertEquals(false, $response['body']['auth' . ucfirst($method['key'])]);
        }

        $email = uniqid() . 'user@localhost.test';
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
            'userId' => ID::unique(),
            'email' => $email,
            'password' => $password,
            'name' => $name,
        ]);

        $this->assertEquals($response['headers']['status-code'], 501);

        $response = $this->client->call(Client::METHOD_POST, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $id,
            'cookie' => 'a_session_' . $id . '=' . $session,
        ]), [
            'teamId' => ID::unique(),
            'name' => 'Arsenal'
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        $teamUid = $response['body']['$id'];

        $response = $this->client->call(Client::METHOD_POST, '/teams/' . $teamUid . '/memberships', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $id,
            'cookie' => 'a_session_' . $id . '=' . $session,
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
            'cookie' => 'a_session_' . $id . '=' . $session,
        ]));

        $this->assertEquals($response['headers']['status-code'], 501);

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
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
            $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $id . '/auth/' . $index, array_merge([
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
    public function testUpdateProjectAuthLimit($data): array
    {
        $id = $data['projectId'] ?? '';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $id . '/auth/limit', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'limit' => 1,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);

        $email = uniqid() . 'user@localhost.test';
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
            'userId' => ID::unique(),
            'email' => $email,
            'password' => $password,
            'name' => $name,
        ]);

        $this->assertEquals($response['headers']['status-code'], 501);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $id . '/auth/limit', array_merge([
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
            'userId' => ID::unique(),
            'email' => $email,
            'password' => $password,
            'name' => $name,
        ]);

        $this->assertEquals($response['headers']['status-code'], 201);

        return $data;
    }

    /**
     * @depends testUpdateProjectAuthLimit
     */
    public function testUpdateProjectAuthSessionsLimit($data): array
    {
        $id = $data['projectId'] ?? '';

        /**
         * Test for failure
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $id . '/auth/max-sessions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'limit' => 0,
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $id . '/auth/max-sessions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'limit' => 1,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals(1, $response['body']['authSessionsLimit']);

        $email = uniqid() . 'user@localhost.test';
        $password = 'password';
        $name = 'User Name';

        /**
         * Create new user
         */
        $response = $this->client->call(Client::METHOD_POST, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $id,
        ]), [
            'userId' => ID::unique(),
            'email' => $email,
            'password' => $password,
            'name' => $name,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        /**
         * create new session
         */
        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $id,
        ]), [
            'email' => $email,
            'password' => $password,
        ]);


        $this->assertEquals(201, $response['headers']['status-code']);
        $sessionId1 = $response['body']['$id'];

        /**
         * create new session
         */
        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $id,
        ]), [
            'email' => $email,
            'password' => $password,
        ]);


        $this->assertEquals(201, $response['headers']['status-code']);
        $sessionCookie = $response['headers']['set-cookie'];
        $sessionId2 = $response['body']['$id'];

        // request was called in parallel and test failed
        sleep(5);

        /**
         * List sessions
         */
        $response = $this->client->call(Client::METHOD_GET, '/account/sessions', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $id,
            'Cookie' => $sessionCookie,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $sessions = $response['body']['sessions'];

        $this->assertEquals(1, count($sessions));
        $this->assertEquals($sessionId2, $sessions[0]['$id']);

        /**
         * Reset Limit
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $id . '/auth/max-sessions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'limit' => 10,
        ]);

        return $data;
    }

    public function testUpdateProjectServiceStatusAdmin(): array
    {
        $team = $this->client->call(Client::METHOD_POST, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ]), [
            'teamId' => ID::unique(),
            'name' => 'Project Test',
        ]);
        $this->assertEquals(201, $team['headers']['status-code']);
        $this->assertNotEmpty($team['body']['$id']);

        $project = $this->client->call(Client::METHOD_POST, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ]), [
            'projectId' => ID::unique(),
            'name' => 'Project Test',
            'teamId' => $team['body']['$id'],
            'region' => 'default'
        ]);

        $this->assertEquals(201, $project['headers']['status-code']);
        $this->assertNotEmpty($project['body']['$id']);

        $id = $project['body']['$id'];
        $services = require('app/config/services.php');

        /**
         * Test for Disabled
         */
        foreach ($services as $service) {
            if (!$service['optional']) {
                continue;
            }

            $key = $service['key'] ?? '';

            $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $id . '/service', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            ]), [
                'service' => $key,
                'status' => false,
            ]);

            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertNotEmpty($response['body']['$id']);

            $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            ]));

            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertNotEmpty($response['body']['$id']);
            $this->assertEquals(false, $response['body']['serviceStatusFor' . ucfirst($key)]);
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
            if (!$service['optional']) {
                continue;
            }

            $key = $service['key'] ?? '';

            $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $id . '/service/', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()), [
                'service' => $key,
                'status' => true,
            ]);
        }

        return ['projectId' => $id];
    }

    /** @depends testUpdateProjectServiceStatusAdmin */
    public function testUpdateProjectServiceStatus($data): void
    {
        $id = $data['projectId'];

        $services = require('app/config/services.php');

        /**
         * Test for Disabled
         */
        foreach ($services as $service) {
            if (!$service['optional']) {
                continue;
            }

            $key = $service['key'] ?? '';

            $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $id . '/service', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            ]), [
                'service' => $key,
                'status' => false,
            ]);

            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertNotEmpty($response['body']['$id']);

            $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            ]));

            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertNotEmpty($response['body']['$id']);
            $this->assertEquals(false, $response['body']['serviceStatusFor' . ucfirst($key)]);
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
            'teamId' => ID::unique(),
            'name' => 'Arsenal'
        ]);

        $this->assertEquals(503, $response['headers']['status-code']);

        // Cleanup

        foreach ($services as $service) {
            $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $id . '/service/', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()), [
                'service' => $service,
                'status' => true,
            ]);
        }
    }

    /** @depends testUpdateProjectServiceStatusAdmin */
    public function testUpdateProjectServiceStatusServer($data): void
    {
        $id = $data['projectId'];

        $services = require('app/config/services.php');

        /**
         * Test for Disabled
         */
        foreach ($services as $service) {
            if (!$service['optional']) {
                continue;
            }

            $key = $service['key'] ?? '';

            $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $id . '/service', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            ]), [
                'service' => $key,
                'status' => false,
            ]);

            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertNotEmpty($response['body']['$id']);

            $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            ]));

            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertNotEmpty($response['body']['$id']);
            $this->assertEquals(false, $response['body']['serviceStatusFor' . ucfirst($key)]);
        }

        // Create API Key
        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $id . '/keys', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ]), [
            'name' => 'Key Test',
            'scopes' => ['functions.read', 'teams.write'],
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        $keyId = $response['body']['$id'];
        $keySecret = $response['body']['secret'];

        /**
         * Request with API Key must succeed
         */
        $response = $this->client->call(Client::METHOD_GET, '/functions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $id,
            'x-appwrite-key' => $keySecret,
            'x-sdk-name' => 'python'
        ]));

        $this->assertEquals(200, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $id,
            'x-appwrite-key' => $keySecret,
            'x-sdk-name' => 'php'
        ]), [
            'teamId' => ID::unique(),
            'name' => 'Arsenal'
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        /** Check that the API key has been updated */
        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id . '/keys/' . $keyId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ]), []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertArrayHasKey('sdks', $response['body']);
        $this->assertCount(2, $response['body']['sdks']);
        $this->assertContains('python', $response['body']['sdks']);
        $this->assertContains('php', $response['body']['sdks']);
        $this->assertArrayHasKey('accessedAt', $response['body']);
        $this->assertNotEmpty($response['body']['accessedAt']);

        // Cleanup

        $response = $this->client->call(Client::METHOD_DELETE, '/projects/' . $id . '/keys/' . $keyId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ]), []);

        $this->assertEquals(204, $response['headers']['status-code']);

        foreach ($services as $service) {
            $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $id . '/service/', array_merge([
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

        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $id . '/webhooks', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Webhook Test',
            'events' => ['users.*.create', 'users.*.update.email'],
            'url' => 'https://appwrite.io',
            'security' => true,
            'httpUser' => 'username',
            'httpPass' => 'password',
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertContains('users.*.create', $response['body']['events']);
        $this->assertContains('users.*.update.email', $response['body']['events']);
        $this->assertCount(2, $response['body']['events']);
        $this->assertEquals('https://appwrite.io', $response['body']['url']);
        $this->assertIsBool($response['body']['security']);
        $this->assertEquals(true, $response['body']['security']);
        $this->assertEquals('username', $response['body']['httpUser']);

        $data = array_merge($data, ['webhookId' => $response['body']['$id'], 'signatureKey' => $response['body']['signatureKey']]);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $id . '/webhooks', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Webhook Test',
            'events' => ['account.unknown', 'users.*.update.email'],
            'url' => 'https://appwrite.io',
            'security' => true,
            'httpUser' => 'username',
            'httpPass' => 'password',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $id . '/webhooks', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Webhook Test',
            'events' => ['users.*.create', 'users.*.update.email'],
            'url' => 'invalid://appwrite.io',
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

        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id . '/webhooks', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(1, $response['body']['total']);

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

        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id . '/webhooks/' . $webhookId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals($webhookId, $response['body']['$id']);
        $this->assertContains('users.*.create', $response['body']['events']);
        $this->assertContains('users.*.update.email', $response['body']['events']);
        $this->assertCount(2, $response['body']['events']);
        $this->assertEquals('https://appwrite.io', $response['body']['url']);
        $this->assertEquals('username', $response['body']['httpUser']);
        $this->assertEquals('password', $response['body']['httpPass']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id . '/webhooks/error', array_merge([
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

        $response = $this->client->call(Client::METHOD_PUT, '/projects/' . $id . '/webhooks/' . $webhookId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Webhook Test Update',
            'events' => ['users.*.delete', 'users.*.sessions.*.delete', 'buckets.*.files.*.create'],
            'url' => 'https://appwrite.io/new',
            'security' => false,
            'httpUser' => '',
            'httpPass' => ''
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals($webhookId, $response['body']['$id']);
        $this->assertEquals('Webhook Test Update', $response['body']['name']);
        $this->assertContains('users.*.delete', $response['body']['events']);
        $this->assertContains('users.*.sessions.*.delete', $response['body']['events']);
        $this->assertContains('buckets.*.files.*.create', $response['body']['events']);
        $this->assertCount(3, $response['body']['events']);
        $this->assertEquals('https://appwrite.io/new', $response['body']['url']);
        $this->assertIsBool($response['body']['security']);
        $this->assertEquals(false, $response['body']['security']);
        $this->assertEquals('', $response['body']['httpUser']);
        $this->assertEquals('', $response['body']['httpPass']);

        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id . '/webhooks/' . $webhookId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals($webhookId, $response['body']['$id']);
        $this->assertEquals('Webhook Test Update', $response['body']['name']);
        $this->assertContains('users.*.delete', $response['body']['events']);
        $this->assertContains('users.*.sessions.*.delete', $response['body']['events']);
        $this->assertContains('buckets.*.files.*.create', $response['body']['events']);
        $this->assertCount(3, $response['body']['events']);
        $this->assertEquals('https://appwrite.io/new', $response['body']['url']);
        $this->assertIsBool($response['body']['security']);
        $this->assertEquals(false, $response['body']['security']);
        $this->assertEquals('', $response['body']['httpUser']);
        $this->assertEquals('', $response['body']['httpPass']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_PUT, '/projects/' . $id . '/webhooks/' . $webhookId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Webhook Test Update',
            'events' => ['users.*.delete', 'users.*.sessions.*.delete', 'buckets.*.files.*.unknown'],
            'url' => 'https://appwrite.io/new',
            'security' => false,
            'httpUser' => '',
            'httpPass' => '',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_PUT, '/projects/' . $id . '/webhooks/' . $webhookId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Webhook Test Update',
            'events' => ['users.*.delete', 'users.*.sessions.*.delete', 'buckets.*.files.*.create'],
            'url' => 'appwrite.io/new',
            'security' => false,
            'httpUser' => '',
            'httpPass' => '',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_PUT, '/projects/' . $id . '/webhooks/' . $webhookId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Webhook Test Update',
            'events' => ['users.*.delete', 'users.*.sessions.*.delete', 'buckets.*.files.*.create'],
            'url' => 'invalid://appwrite.io/new',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        return $data;
    }

    /**
     * @depends testCreateProjectWebhook
     */
    public function testUpdateProjectWebhookSignature($data): void
    {
        $id = $data['projectId'] ?? '';
        $webhookId = $data['webhookId'] ?? '';
        $signatureKey = $data['signatureKey'] ?? '';

        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $id . '/webhooks/' . $webhookId . '/signature', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['signatureKey']);
        $this->assertNotEquals($signatureKey, $response['body']['signatureKey']);
    }

    /**
     * @depends testCreateProjectWebhook
     */
    public function testDeleteProjectWebhook($data): array
    {
        $id = $data['projectId'] ?? '';
        $webhookId = $data['webhookId'] ?? '';

        $response = $this->client->call(Client::METHOD_DELETE, '/projects/' . $id . '/webhooks/' . $webhookId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(204, $response['headers']['status-code']);
        $this->assertEmpty($response['body']);

        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id . '/webhooks/' . $webhookId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(404, $response['headers']['status-code']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_DELETE, '/projects/' . $id . '/webhooks/error', array_merge([
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

        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $id . '/keys', array_merge([
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
        $this->assertArrayHasKey('sdks', $response['body']);
        $this->assertEmpty($response['body']['sdks']);
        $this->assertArrayHasKey('accessedAt', $response['body']);
        $this->assertEmpty($response['body']['accessedAt']);

        $data = array_merge($data, [
            'keyId' => $response['body']['$id'],
            'secret' => $response['body']['secret']
        ]);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $id . '/keys', array_merge([
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

        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id . '/keys', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);


        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(1, $response['body']['total']);

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

        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id . '/keys/' . $keyId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $keyId
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals($keyId, $response['body']['$id']);
        $this->assertEquals('Key Test', $response['body']['name']);
        $this->assertContains('teams.read', $response['body']['scopes']);
        $this->assertContains('teams.write', $response['body']['scopes']);
        $this->assertCount(2, $response['body']['scopes']);
        $this->assertNotEmpty($response['body']['secret']);
        $this->assertArrayHasKey('sdks', $response['body']);
        $this->assertEmpty($response['body']['sdks']);
        $this->assertArrayHasKey('accessedAt', $response['body']);
        $this->assertEmpty($response['body']['accessedAt']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id . '/keys/error', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(404, $response['headers']['status-code']);

        return $data;
    }

    /**
     * @depends testCreateProject
     */
    public function testValidateProjectKey($data): void
    {
        $id = $data['projectId'] ?? '';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $id . '/keys', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Key Test',
            'scopes' => ['health.read'],
            'expire' => DateTime::addSeconds(new \DateTime(), 3600),
        ]);

        $response = $this->client->call(Client::METHOD_GET, '/health', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $id,
            'x-appwrite-key' => $response['body']['secret']
        ], []);

        $this->assertEquals(200, $response['headers']['status-code']);

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $id . '/keys', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Key Test',
            'scopes' => ['health.read'],
            'expire' => null,
        ]);

        $response = $this->client->call(Client::METHOD_GET, '/health', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $id,
            'x-appwrite-key' => $response['body']['secret']
        ], []);

        $this->assertEquals(200, $response['headers']['status-code']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $id . '/keys', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Key Test',
            'scopes' => ['health.read'],
            'expire' => DateTime::addSeconds(new \DateTime(), -3600),
        ]);

        $response = $this->client->call(Client::METHOD_GET, '/health', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $id,
            'x-appwrite-key' => $response['body']['secret']
        ], []);

        $this->assertEquals(401, $response['headers']['status-code']);
    }


    /**
     * @depends testCreateProjectKey
     */
    public function testUpdateProjectKey($data): array
    {
        $id = $data['projectId'] ?? '';
        $keyId = $data['keyId'] ?? '';

        $response = $this->client->call(Client::METHOD_PUT, '/projects/' . $id . '/keys/' . $keyId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Key Test Update',
            'scopes' => ['users.read', 'users.write', 'collections.read'],
            'expire' => DateTime::addSeconds(new \DateTime(), 360),
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals($keyId, $response['body']['$id']);
        $this->assertEquals('Key Test Update', $response['body']['name']);
        $this->assertContains('users.read', $response['body']['scopes']);
        $this->assertContains('users.write', $response['body']['scopes']);
        $this->assertContains('collections.read', $response['body']['scopes']);
        $this->assertCount(3, $response['body']['scopes']);
        $this->assertArrayHasKey('sdks', $response['body']);
        $this->assertEmpty($response['body']['sdks']);
        $this->assertArrayHasKey('accessedAt', $response['body']);
        $this->assertEmpty($response['body']['accessedAt']);

        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id . '/keys/' . $keyId, array_merge([
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
        $this->assertArrayHasKey('sdks', $response['body']);
        $this->assertEmpty($response['body']['sdks']);
        $this->assertArrayHasKey('accessedAt', $response['body']);
        $this->assertEmpty($response['body']['accessedAt']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_PUT, '/projects/' . $id . '/keys/' . $keyId, array_merge([
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

        $response = $this->client->call(Client::METHOD_DELETE, '/projects/' . $id . '/keys/' . $keyId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(204, $response['headers']['status-code']);
        $this->assertEmpty($response['body']);

        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id . '/keys/' . $keyId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(404, $response['headers']['status-code']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_DELETE, '/projects/' . $id . '/keys/error', array_merge([
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

        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $id . '/platforms', array_merge([
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

        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $id . '/platforms', array_merge([
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

        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $id . '/platforms', array_merge([
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

        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $id . '/platforms', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'type' => 'flutter-web',
            'name' => 'Flutter App (Web)',
            'key' => '',
            'store' => '',
            'hostname' => 'flutter.appwrite.io',
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals('flutter-web', $response['body']['type']);
        $this->assertEquals('Flutter App (Web)', $response['body']['name']);
        $this->assertEquals('', $response['body']['key']);
        $this->assertEquals('', $response['body']['store']);
        $this->assertEquals('flutter.appwrite.io', $response['body']['hostname']);

        $data = array_merge($data, ['platformFultterWebId' => $response['body']['$id']]);

        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $id . '/platforms', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'type' => 'apple-ios',
            'name' => 'iOS App',
            'key' => 'com.example.ios',
            'store' => '',
            'hostname' => '',
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals('apple-ios', $response['body']['type']);
        $this->assertEquals('iOS App', $response['body']['name']);
        $this->assertEquals('com.example.ios', $response['body']['key']);
        $this->assertEquals('', $response['body']['store']);
        $this->assertEquals('', $response['body']['hostname']);

        $data = array_merge($data, ['platformAppleIosId' => $response['body']['$id']]);

        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $id . '/platforms', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'type' => 'apple-macos',
            'name' => 'macOS App',
            'key' => 'com.example.macos',
            'store' => '',
            'hostname' => '',
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals('apple-macos', $response['body']['type']);
        $this->assertEquals('macOS App', $response['body']['name']);
        $this->assertEquals('com.example.macos', $response['body']['key']);
        $this->assertEquals('', $response['body']['store']);
        $this->assertEquals('', $response['body']['hostname']);

        $data = array_merge($data, ['platformAppleMacOsId' => $response['body']['$id']]);

        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $id . '/platforms', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'type' => 'apple-watchos',
            'name' => 'watchOS App',
            'key' => 'com.example.watchos',
            'store' => '',
            'hostname' => '',
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals('apple-watchos', $response['body']['type']);
        $this->assertEquals('watchOS App', $response['body']['name']);
        $this->assertEquals('com.example.watchos', $response['body']['key']);
        $this->assertEquals('', $response['body']['store']);
        $this->assertEquals('', $response['body']['hostname']);

        $data = array_merge($data, ['platformAppleWatchOsId' => $response['body']['$id']]);

        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $id . '/platforms', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'type' => 'apple-tvos',
            'name' => 'tvOS App',
            'key' => 'com.example.tvos',
            'store' => '',
            'hostname' => '',
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals('apple-tvos', $response['body']['type']);
        $this->assertEquals('tvOS App', $response['body']['name']);
        $this->assertEquals('com.example.tvos', $response['body']['key']);
        $this->assertEquals('', $response['body']['store']);
        $this->assertEquals('', $response['body']['hostname']);

        $data = array_merge($data, ['platformAppleTvOsId' => $response['body']['$id']]);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $id . '/platforms', array_merge([
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

        return $data;
    }

    /**
     * @depends testCreateProjectPlatform
     */
    public function testListProjectPlatform($data): array
    {
        $id = $data['projectId'] ?? '';

        sleep(1);

        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id . '/platforms', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(8, $response['body']['total']);

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

        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id . '/platforms/' . $platformWebId, array_merge([
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

        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id . '/platforms/' . $platformFultteriOSId, array_merge([
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

        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id . '/platforms/' . $platformFultterAndroidId, array_merge([
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

        $platformFultterWebId = $data['platformFultterWebId'] ?? '';

        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id . '/platforms/' . $platformFultterWebId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals($platformFultterWebId, $response['body']['$id']);
        $this->assertEquals('flutter-web', $response['body']['type']);
        $this->assertEquals('Flutter App (Web)', $response['body']['name']);
        $this->assertEquals('', $response['body']['key']);
        $this->assertEquals('', $response['body']['store']);
        $this->assertEquals('flutter.appwrite.io', $response['body']['hostname']);

        $platformAppleIosId = $data['platformAppleIosId'] ?? '';

        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id . '/platforms/' . $platformAppleIosId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals($platformAppleIosId, $response['body']['$id']);
        $this->assertEquals('apple-ios', $response['body']['type']);
        $this->assertEquals('iOS App', $response['body']['name']);
        $this->assertEquals('com.example.ios', $response['body']['key']);
        $this->assertEquals('', $response['body']['store']);
        $this->assertEquals('', $response['body']['hostname']);

        $platformAppleMacOsId = $data['platformAppleMacOsId'] ?? '';

        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id . '/platforms/' . $platformAppleMacOsId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals($platformAppleMacOsId, $response['body']['$id']);
        $this->assertEquals('apple-macos', $response['body']['type']);
        $this->assertEquals('macOS App', $response['body']['name']);
        $this->assertEquals('com.example.macos', $response['body']['key']);
        $this->assertEquals('', $response['body']['store']);
        $this->assertEquals('', $response['body']['hostname']);

        $platformAppleWatchOsId = $data['platformAppleWatchOsId'] ?? '';

        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id . '/platforms/' . $platformAppleWatchOsId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals($platformAppleWatchOsId, $response['body']['$id']);
        $this->assertEquals('apple-watchos', $response['body']['type']);
        $this->assertEquals('watchOS App', $response['body']['name']);
        $this->assertEquals('com.example.watchos', $response['body']['key']);
        $this->assertEquals('', $response['body']['store']);
        $this->assertEquals('', $response['body']['hostname']);

        $platformAppleTvOsId = $data['platformAppleTvOsId'] ?? '';

        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id . '/platforms/' . $platformAppleTvOsId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals($platformAppleTvOsId, $response['body']['$id']);
        $this->assertEquals('apple-tvos', $response['body']['type']);
        $this->assertEquals('tvOS App', $response['body']['name']);
        $this->assertEquals('com.example.tvos', $response['body']['key']);
        $this->assertEquals('', $response['body']['store']);
        $this->assertEquals('', $response['body']['hostname']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id . '/platforms/error', array_merge([
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

        $response = $this->client->call(Client::METHOD_PUT, '/projects/' . $id . '/platforms/' . $platformWebId, array_merge([
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

        $response = $this->client->call(Client::METHOD_PUT, '/projects/' . $id . '/platforms/' . $platformFultteriOSId, array_merge([
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

        $response = $this->client->call(Client::METHOD_PUT, '/projects/' . $id . '/platforms/' . $platformFultterAndroidId, array_merge([
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

        $platformFultterWebId = $data['platformFultterWebId'] ?? '';

        $response = $this->client->call(Client::METHOD_PUT, '/projects/' . $id . '/platforms/' . $platformFultterWebId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Flutter App (Web) 2',
            'key' => '',
            'store' => '',
            'hostname' => 'flutter2.appwrite.io',
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals($platformFultterWebId, $response['body']['$id']);
        $this->assertEquals('flutter-web', $response['body']['type']);
        $this->assertEquals('Flutter App (Web) 2', $response['body']['name']);
        $this->assertEquals('', $response['body']['key']);
        $this->assertEquals('', $response['body']['store']);
        $this->assertEquals('flutter2.appwrite.io', $response['body']['hostname']);

        $platformAppleIosId = $data['platformAppleIosId'] ?? '';

        $response = $this->client->call(Client::METHOD_PUT, '/projects/' . $id . '/platforms/' . $platformAppleIosId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'iOS App 2',
            'key' => 'com.example.ios2',
            'store' => '',
            'hostname' => '',
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals($platformAppleIosId, $response['body']['$id']);
        $this->assertEquals('apple-ios', $response['body']['type']);
        $this->assertEquals('iOS App 2', $response['body']['name']);
        $this->assertEquals('com.example.ios2', $response['body']['key']);
        $this->assertEquals('', $response['body']['store']);
        $this->assertEquals('', $response['body']['hostname']);

        $platformAppleMacOsId = $data['platformAppleMacOsId'] ?? '';

        $response = $this->client->call(Client::METHOD_PUT, '/projects/' . $id . '/platforms/' . $platformAppleMacOsId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'macOS App 2',
            'key' => 'com.example.macos2',
            'store' => '',
            'hostname' => '',
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals($platformAppleMacOsId, $response['body']['$id']);
        $this->assertEquals('apple-macos', $response['body']['type']);
        $this->assertEquals('macOS App 2', $response['body']['name']);
        $this->assertEquals('com.example.macos2', $response['body']['key']);
        $this->assertEquals('', $response['body']['store']);
        $this->assertEquals('', $response['body']['hostname']);

        $platformAppleWatchOsId = $data['platformAppleWatchOsId'] ?? '';

        $response = $this->client->call(Client::METHOD_PUT, '/projects/' . $id . '/platforms/' . $platformAppleWatchOsId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'watchOS App 2',
            'key' => 'com.example.watchos2',
            'store' => '',
            'hostname' => '',
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals($platformAppleWatchOsId, $response['body']['$id']);
        $this->assertEquals('apple-watchos', $response['body']['type']);
        $this->assertEquals('watchOS App 2', $response['body']['name']);
        $this->assertEquals('com.example.watchos2', $response['body']['key']);
        $this->assertEquals('', $response['body']['store']);
        $this->assertEquals('', $response['body']['hostname']);

        $platformAppleTvOsId = $data['platformAppleTvOsId'] ?? '';

        $response = $this->client->call(Client::METHOD_PUT, '/projects/' . $id . '/platforms/' . $platformAppleTvOsId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'tvOS App 2',
            'key' => 'com.example.tvos2',
            'store' => '',
            'hostname' => '',
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals($platformAppleTvOsId, $response['body']['$id']);
        $this->assertEquals('apple-tvos', $response['body']['type']);
        $this->assertEquals('tvOS App 2', $response['body']['name']);
        $this->assertEquals('com.example.tvos2', $response['body']['key']);
        $this->assertEquals('', $response['body']['store']);
        $this->assertEquals('', $response['body']['hostname']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_PUT, '/projects/' . $id . '/platforms/error', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Flutter App (Android) 2',
            'key' => 'com.example.android2',
            'store' => '',
            'hostname' => '',
        ]);

        $this->assertEquals(404, $response['headers']['status-code']);

        return $data;
    }

    /**
     * @depends testCreateProjectPlatform
     */
    public function testDeleteProjectPlatform($data): array
    {
        $id = $data['projectId'] ?? '';

        $platformWebId = $data['platformWebId'] ?? '';

        $response = $this->client->call(Client::METHOD_DELETE, '/projects/' . $id . '/platforms/' . $platformWebId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(204, $response['headers']['status-code']);
        $this->assertEmpty($response['body']);

        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id . '/platforms/' . $platformWebId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(404, $response['headers']['status-code']);

        $platformFultteriOSId = $data['platformFultteriOSId'] ?? '';

        $response = $this->client->call(Client::METHOD_DELETE, '/projects/' . $id . '/platforms/' . $platformFultteriOSId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(204, $response['headers']['status-code']);
        $this->assertEmpty($response['body']);

        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id . '/platforms/' . $platformFultteriOSId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(404, $response['headers']['status-code']);

        $platformFultterAndroidId = $data['platformFultterAndroidId'] ?? '';

        $response = $this->client->call(Client::METHOD_DELETE, '/projects/' . $id . '/platforms/' . $platformFultterAndroidId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(204, $response['headers']['status-code']);
        $this->assertEmpty($response['body']);

        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id . '/platforms/' . $platformFultterAndroidId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(404, $response['headers']['status-code']);

        $platformFultterWebId = $data['platformFultterWebId'] ?? '';

        $response = $this->client->call(Client::METHOD_DELETE, '/projects/' . $id . '/platforms/' . $platformFultterWebId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(204, $response['headers']['status-code']);
        $this->assertEmpty($response['body']);

        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id . '/platforms/' . $platformFultterWebId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(404, $response['headers']['status-code']);

        $platformAppleIosId = $data['platformAppleIosId'] ?? '';

        $response = $this->client->call(Client::METHOD_DELETE, '/projects/' . $id . '/platforms/' . $platformAppleIosId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(204, $response['headers']['status-code']);
        $this->assertEmpty($response['body']);

        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id . '/platforms/' . $platformAppleIosId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(404, $response['headers']['status-code']);

        $platformAppleMacOsId = $data['platformAppleMacOsId'] ?? '';

        $response = $this->client->call(Client::METHOD_DELETE, '/projects/' . $id . '/platforms/' . $platformAppleMacOsId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(204, $response['headers']['status-code']);
        $this->assertEmpty($response['body']);

        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id . '/platforms/' . $platformAppleMacOsId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(404, $response['headers']['status-code']);

        $platformAppleWatchOsId = $data['platformAppleWatchOsId'] ?? '';

        $response = $this->client->call(Client::METHOD_DELETE, '/projects/' . $id . '/platforms/' . $platformAppleWatchOsId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(204, $response['headers']['status-code']);
        $this->assertEmpty($response['body']);

        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id . '/platforms/' . $platformAppleWatchOsId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(404, $response['headers']['status-code']);

        $platformAppleTvOsId = $data['platformAppleTvOsId'] ?? '';

        $response = $this->client->call(Client::METHOD_DELETE, '/projects/' . $id . '/platforms/' . $platformAppleTvOsId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(204, $response['headers']['status-code']);
        $this->assertEmpty($response['body']);

        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id . '/platforms/' . $platformAppleTvOsId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(404, $response['headers']['status-code']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_DELETE, '/projects/' . $id . '/webhooks/error', array_merge([
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

        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $id . '/domains', array_merge([
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
        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $id . '/platforms', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'domain' => '123',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $id . '/platforms', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'type' => 'web',
            'name' => 'Too Long Hostname',
            'key' => '',
            'store' => '',
            'hostname' => \str_repeat("bestdomain", 25) . '.com' // 250 + 4 chars total (exactly above limit)
        ]);

        return $data;
    }

    /**
     * @depends testCreateProjectDomain
     */
    public function testListProjectDomain($data): array
    {
        $id = $data['projectId'] ?? '';

        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id . '/domains', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(1, $response['body']['total']);

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

        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id . '/domains/' . $domainId, array_merge([
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
        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id . '/domains/error', array_merge([
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

        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $id . '/domains/' . $domainId . '/verification', array_merge([
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

        $response = $this->client->call(Client::METHOD_DELETE, '/projects/' . $id . '/domains/' . $domainId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(204, $response['headers']['status-code']);
        $this->assertEmpty($response['body']);

        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id . '/domains/' . $domainId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(404, $response['headers']['status-code']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_DELETE, '/projects/' . $id . '/domains/error', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(404, $response['headers']['status-code']);

        return $data;
    }

    public function testDeleteProject(): array
    {
        $data = [];

        // Create a team and a project
        $team = $this->client->call(Client::METHOD_POST, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'teamId' => ID::unique(),
            'name' => 'Amating Team',
        ]);

        $this->assertEquals(201, $team['headers']['status-code']);
        $this->assertEquals('Amating Team', $team['body']['name']);
        $this->assertNotEmpty($team['body']['$id']);

        $teamId = $team['body']['$id'];

        $project = $this->client->call(Client::METHOD_POST, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'projectId' => ID::unique(),
            'name' => 'Amazing Project',
            'teamId' => $teamId,
            'region' => 'default'
        ]);

        $this->assertEquals(201, $project['headers']['status-code']);
        $this->assertEquals('Amazing Project', $project['body']['name']);
        $this->assertEquals($teamId, $project['body']['teamId']);
        $this->assertNotEmpty($project['body']['$id']);

        $projectId = $project['body']['$id'];

        // Ensure I can get both team and project
        $team = $this->client->call(Client::METHOD_GET, '/teams/' . $teamId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $team['headers']['status-code']);

        $project = $this->client->call(Client::METHOD_GET, '/projects/' . $projectId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $project['headers']['status-code']);

        // Delete team
        $team = $this->client->call(Client::METHOD_DELETE, '/projects/' . $projectId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'password' => 'password'
        ]);

        $this->assertEquals(204, $team['headers']['status-code']);

        // Ensure I can get team but not a project
        $team = $this->client->call(Client::METHOD_GET, '/teams/' . $teamId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $team['headers']['status-code']);

        $project = $this->client->call(Client::METHOD_GET, '/projects/' . $projectId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(404, $project['headers']['status-code']);

        return $data;
    }
}
