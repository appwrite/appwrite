<?php

namespace Tests\E2E\Services\Projects;

use Appwrite\Extend\Exception;
use Appwrite\Tests\Async;
use Tests\E2E\Client;
use Tests\E2E\General\UsageTest;
use Tests\E2E\Scopes\ProjectConsole;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;
use Utopia\System\System;

class ProjectsConsoleClientTest extends Scope
{
    use ProjectsBase;
    use ProjectConsole;
    use SideClient;
    use Async;

    /**
     * @group devKeys
     * @group smtpAndTemplates
     * @group projectsCRUD */
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
            'region' => System::getEnv('_APP_REGION', 'default')
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
            'region' => System::getEnv('_APP_REGION', 'default')
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'projectId' => ID::unique(),
            'name' => 'Project Test',
            'region' => System::getEnv('_APP_REGION', 'default')
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);

        return [
            'projectId' => $projectId,
            'teamId' => $team['body']['$id']
        ];
    }

    public function testCreateDuplicateProject(): void
    {
        // Create a team
        $team = $this->client->call(Client::METHOD_POST, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'teamId' => ID::unique(),
            'name' => 'Duplicate Test Team',
        ]);

        $this->assertEquals(201, $team['headers']['status-code']);
        $teamId = $team['body']['$id'];

        // Create a project
        $projectId = ID::unique();
        $response = $this->client->call(Client::METHOD_POST, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'projectId' => $projectId,
            'name' => 'Original Project',
            'teamId' => $teamId,
            'region' => System::getEnv('_APP_REGION', 'default')
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_POST, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'projectId' => $projectId,
            'name' => 'Project Duplicate',
            'teamId' => $teamId,
            'region' => System::getEnv('_APP_REGION', 'default')
        ]);

        $this->assertEquals(409, $response['headers']['status-code']);
        $this->assertEquals(409, $response['body']['code']);
        $this->assertEquals(Exception::PROJECT_ALREADY_EXISTS, $response['body']['type']);
    }

    /** @group projectsCRUD */
    public function testTransferProjectTeam()
    {
        /**
         * Test for SUCCESS
         */
        $team = $this->client->call(Client::METHOD_POST, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'teamId' => ID::unique(),
            'name' => 'Team 1',
        ]);

        $this->assertEquals(201, $team['headers']['status-code']);
        $this->assertEquals('Team 1', $team['body']['name']);
        $this->assertNotEmpty($team['body']['$id']);

        $team1 = $team['body']['$id'];

        $team = $this->client->call(Client::METHOD_POST, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'teamId' => ID::unique(),
            'name' => 'Team 2',
        ]);

        $this->assertEquals(201, $team['headers']['status-code']);
        $this->assertEquals('Team 2', $team['body']['name']);
        $this->assertNotEmpty($team['body']['$id']);

        $team2 = $team['body']['$id'];

        $response = $this->client->call(Client::METHOD_POST, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'projectId' => ID::unique(),
            'name' => 'Team 1 Project',
            'teamId' => $team1,
            'region' => System::getEnv('_APP_REGION', 'default'),
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals('Team 1 Project', $response['body']['name']);
        $this->assertEquals($team1, $response['body']['teamId']);
        $this->assertArrayHasKey('platforms', $response['body']);
        $this->assertArrayHasKey('webhooks', $response['body']);
        $this->assertArrayHasKey('keys', $response['body']);

        $projectId = $response['body']['$id'];

        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $projectId . '/team', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'teamId' => $team2,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals('Team 1 Project', $response['body']['name']);
        $this->assertEquals($team2, $response['body']['teamId']);
    }

    /**
     * @group projectsCRUD
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
        $this->assertEquals($response['body']['total'], 3);
        $this->assertIsArray($response['body']['projects']);
        $this->assertCount(3, $response['body']['projects']);
        $this->assertEquals($response['body']['projects'][0]['name'], 'Project Test');

        $response = $this->client->call(Client::METHOD_GET, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders(), [
            'search' => 'Project Test'
        ]));

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertEquals(3, $response['body']['total']);
        $this->assertIsArray($response['body']['projects']);
        $this->assertCount(3, $response['body']['projects']);
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
            'region' => System::getEnv('_APP_REGION', 'default')
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
            'queries' => [
                Query::equal('teamId', [$team['body']['$id']])->toString(),
            ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertCount(1, $response['body']['projects']);
        $this->assertEquals($team['body']['$id'], $response['body']['projects'][0]['teamId']);

        $response = $this->client->call(Client::METHOD_GET, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::limit(1)->toString(),
            ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertCount(1, $response['body']['projects']);
        $this->assertEquals('Project Test', $response['body']['projects'][0]['name']);

        $response = $this->client->call(Client::METHOD_GET, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::offset(3)->toString(),
            ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertCount(1, $response['body']['projects']);
        $this->assertEquals('Project Test 2', $response['body']['projects'][0]['name']);

        $response = $this->client->call(Client::METHOD_GET, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::equal('name', ['Project Test 2'])->toString(),
            ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertCount(1, $response['body']['projects']);
        $this->assertEquals('Project Test 2', $response['body']['projects'][0]['name']);

        $response = $this->client->call(Client::METHOD_GET, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::orderDesc()->toString(),
            ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertCount(4, $response['body']['projects']);
        $this->assertEquals('Project Test 2', $response['body']['projects'][0]['name']);
        $this->assertEquals('Team 1 Project', $response['body']['projects'][1]['name']);

        $response = $this->client->call(Client::METHOD_GET, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertCount(4, $response['body']['projects']);
        $this->assertEquals('Project Test', $response['body']['projects'][0]['name']);
        $this->assertEquals('Team 1 Project', $response['body']['projects'][2]['name']);

        $response = $this->client->call(Client::METHOD_GET, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::cursorAfter(new Document(['$id' => $response['body']['projects'][0]['$id']]))->toString(),
            ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertCount(3, $response['body']['projects']);
        $this->assertEquals('Team 1 Project', $response['body']['projects'][1]['name']);

        $response = $this->client->call(Client::METHOD_GET, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::cursorBefore(new Document(['$id' => $response['body']['projects'][0]['$id']]))->toString(),
            ],
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
            'queries' => [
                Query::cursorAfter(new Document(['$id' => 'unknown']))->toString(),
            ],
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        return $data;
    }

    public function testGetProject(): void
    {
        // Create a team
        $team = $this->client->call(Client::METHOD_POST, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'teamId' => ID::unique(),
            'name' => 'Get Project Test Team',
        ]);

        $this->assertEquals(201, $team['headers']['status-code']);

        // Create a project
        $response = $this->client->call(Client::METHOD_POST, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'projectId' => ID::unique(),
            'name' => 'Project Test',
            'teamId' => $team['body']['$id'],
            'region' => System::getEnv('_APP_REGION', 'default')
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $id = $response['body']['$id'];

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
    }

    /**
     * @depends testCreateProject
     */
    public function testGetProjectUsage($data): array
    {
        $this->markTestIncomplete(
            'This test is failing right now due to functions collection.'
        );
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/project/usage', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'startDate' => UsageTest::getToday(),
            'endDate' => UsageTest::getTomorrow(),
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(8, count($response['body']));
        $this->assertNotEmpty($response['body']);
        $this->assertIsArray($response['body']['requests']);
        $this->assertIsArray($response['body']['network']);
        $this->assertIsNumeric($response['body']['executionsTotal']);
        $this->assertIsNumeric($response['body']['rowsTotal']);
        $this->assertIsNumeric($response['body']['databasesTotal']);
        $this->assertIsNumeric($response['body']['bucketsTotal']);
        $this->assertIsNumeric($response['body']['usersTotal']);
        $this->assertIsNumeric($response['body']['filesStorageTotal']);
        $this->assertIsNumeric($response['body']['deploymentStorageTotal']);
        $this->assertIsNumeric($response['body']['authPhoneTotal']);
        $this->assertIsNumeric($response['body']['authPhoneEstimate']);


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

    public function testUpdateProject(): void
    {
        // Create a team
        $team = $this->client->call(Client::METHOD_POST, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'teamId' => ID::unique(),
            'name' => 'Update Project Test Team',
        ]);

        $this->assertEquals(201, $team['headers']['status-code']);
        $teamId = $team['body']['$id'];

        // Create a project
        $response = $this->client->call(Client::METHOD_POST, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'projectId' => ID::unique(),
            'name' => 'Project Test',
            'teamId' => $teamId,
            'region' => System::getEnv('_APP_REGION', 'default')
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $id = $response['body']['$id'];

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

        $this->assertEquals(401, $response['headers']['status-code']);
    }

    /**
     * @group smtpAndTemplates
     * @depends testCreateProject
     */
    public function testUpdateProjectSMTP($data): array
    {
        $id = $data['projectId'];
        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $id . '/smtp', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'enabled' => true,
            'senderEmail' => 'mailer@appwrite.io',
            'senderName' => 'Mailer',
            'host' => 'maildev',
            'port' => 1025,
            'username' => 'user',
            'password' => 'password',
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertTrue($response['body']['smtpEnabled']);
        $this->assertEquals('mailer@appwrite.io', $response['body']['smtpSenderEmail']);
        $this->assertEquals('Mailer', $response['body']['smtpSenderName']);
        $this->assertEquals('maildev', $response['body']['smtpHost']);
        $this->assertEquals(1025, $response['body']['smtpPort']);
        $this->assertEquals('user', $response['body']['smtpUsername']);
        $this->assertEquals('password', $response['body']['smtpPassword']);
        $this->assertEquals('', $response['body']['smtpSecure']);

        /** Test Reading Project */
        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertTrue($response['body']['smtpEnabled']);
        $this->assertEquals('mailer@appwrite.io', $response['body']['smtpSenderEmail']);
        $this->assertEquals('Mailer', $response['body']['smtpSenderName']);
        $this->assertEquals('maildev', $response['body']['smtpHost']);
        $this->assertEquals(1025, $response['body']['smtpPort']);
        $this->assertEquals('user', $response['body']['smtpUsername']);
        $this->assertEquals('password', $response['body']['smtpPassword']);
        $this->assertEquals('', $response['body']['smtpSecure']);

        return $data;
    }

    /**
     * @group smtpAndTemplates
     */
    public function testCreateProjectSMTPTests(): void
    {
        // Create a team
        $team = $this->client->call(Client::METHOD_POST, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'teamId' => ID::unique(),
            'name' => 'Create Project SMTP Tests Test Team',
        ]);

        $this->assertEquals(201, $team['headers']['status-code']);
        $teamId = $team['body']['$id'];

        // Create a project
        $response = $this->client->call(Client::METHOD_POST, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'projectId' => ID::unique(),
            'name' => 'Project Test',
            'teamId' => $teamId,
            'region' => System::getEnv('_APP_REGION', 'default')
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $id = $response['body']['$id'];

        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $id . '/smtp/tests', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'emails' => ['testuser@appwrite.io', 'testusertwo@appwrite.io'],
            'senderEmail' => 'custommailer@appwrite.io',
            'senderName' => 'Custom Mailer',
            'replyTo' => 'reply@appwrite.io',
            'host' => 'maildev',
            'port' => 1025,
            'username' => '',
            'password' => '',
        ]);

        $this->assertEquals(204, $response['headers']['status-code']);

        $emails = $this->getLastEmail(2);
        $this->assertCount(2, $emails);
        $this->assertEquals('custommailer@appwrite.io', $emails[0]['from'][0]['address']);
        $this->assertEquals('Custom Mailer', $emails[0]['from'][0]['name']);
        $this->assertEquals('reply@appwrite.io', $emails[0]['replyTo'][0]['address']);
        $this->assertEquals('Custom Mailer', $emails[0]['replyTo'][0]['name']);
        $this->assertEquals('Custom SMTP email sample', $emails[0]['subject']);
        $this->assertStringContainsStringIgnoringCase('working correctly', $emails[0]['text']);
        $this->assertStringContainsStringIgnoringCase('working correctly', $emails[0]['html']);
        $this->assertStringContainsStringIgnoringCase('251 Little Falls Drive', $emails[0]['text']);
        $this->assertStringContainsStringIgnoringCase('251 Little Falls Drive', $emails[0]['html']);

        $to = [
            $emails[0]['to'][0]['address'],
            $emails[1]['to'][0]['address']
        ];
        \sort($to);

        $this->assertEquals('testuser@appwrite.io', $to[0]);
        $this->assertEquals('testusertwo@appwrite.io', $to[1]);

        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $id . '/smtp/tests', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'emails' => ['u1@appwrite.io', 'u2@appwrite.io', 'u3@appwrite.io', 'u4@appwrite.io', 'u5@appwrite.io', 'u6@appwrite.io', 'u7@appwrite.io', 'u8@appwrite.io', 'u9@appwrite.io', 'u10@appwrite.io'],
            'senderEmail' => 'custommailer@appwrite.io',
            'senderName' => 'Custom Mailer',
            'replyTo' => 'reply@appwrite.io',
            'host' => 'maildev',
            'port' => 1025,
            'username' => '',
            'password' => '',
        ]);

        $this->assertEquals(204, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $id . '/smtp/tests', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'emails' => ['u1@appwrite.io', 'u2@appwrite.io', 'u3@appwrite.io', 'u4@appwrite.io', 'u5@appwrite.io', 'u6@appwrite.io', 'u7@appwrite.io', 'u8@appwrite.io', 'u9@appwrite.io', 'u10@appwrite.io', 'u11@appwrite.io'],
            'senderEmail' => 'custommailer@appwrite.io',
            'senderName' => 'Custom Mailer',
            'replyTo' => 'reply@appwrite.io',
            'host' => 'maildev',
            'port' => 1025,
            'username' => '',
            'password' => '',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
    }

    /**
     * @group smtpAndTemplates
     * @depends testUpdateProjectSMTP
     */
    public function testUpdateTemplates($data): array
    {
        $id = $data['projectId'];

        /** Get Default Email Template */
        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id . '/templates/email/verification/en-us', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('Account Verification', $response['body']['subject']);
        $this->assertEquals('', $response['body']['senderEmail']);
        $this->assertEquals('verification', $response['body']['type']);
        $this->assertEquals('en-us', $response['body']['locale']);

        /** Update Email template */
        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $id . '/templates/email/verification/en-us', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'subject' => 'Please verify your email',
            'message' => 'Please verify your email {{url}}',
            'senderName' => 'Appwrite Custom',
            'senderEmail' => 'custom@appwrite.io',
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('Please verify your email', $response['body']['subject']);
        $this->assertEquals('Appwrite Custom', $response['body']['senderName']);
        $this->assertEquals('custom@appwrite.io', $response['body']['senderEmail']);
        $this->assertEquals('verification', $response['body']['type']);
        $this->assertEquals('en-us', $response['body']['locale']);
        $this->assertEquals('Please verify your email {{url}}', $response['body']['message']);

        /** Get Updated Email Template */
        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id . '/templates/email/verification/en-us', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('Please verify your email', $response['body']['subject']);
        $this->assertEquals('Appwrite Custom', $response['body']['senderName']);
        $this->assertEquals('custom@appwrite.io', $response['body']['senderEmail']);
        $this->assertEquals('verification', $response['body']['type']);
        $this->assertEquals('en-us', $response['body']['locale']);
        $this->assertEquals('Please verify your email {{url}}', $response['body']['message']);

        // Temporary disabled until implemented
        // /** Get Default SMS Template */
        // $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id . '/templates/sms/verification/en-us', array_merge([
        //     'content-type' => 'application/json',
        //     'x-appwrite-project' => $this->getProject()['$id'],
        // ], $this->getHeaders()));

        // $this->assertEquals(200, $response['headers']['status-code']);
        // $this->assertEquals('verification', $response['body']['type']);
        // $this->assertEquals('en-us', $response['body']['locale']);
        // $this->assertEquals('{{token}}', $response['body']['message']);

        // /** Update SMS template */
        // $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $id . '/templates/sms/verification/en-us', array_merge([
        //     'content-type' => 'application/json',
        //     'x-appwrite-project' => $this->getProject()['$id'],
        // ], $this->getHeaders()), [
        //     'message' => 'Please verify your email {{token}}',
        // ]);

        // $this->assertEquals(200, $response['headers']['status-code']);
        // $this->assertEquals('verification', $response['body']['type']);
        // $this->assertEquals('en-us', $response['body']['locale']);
        // $this->assertEquals('Please verify your email {{token}}', $response['body']['message']);

        // /** Get Updated SMS Template */
        // $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id . '/templates/sms/verification/en-us', array_merge([
        //     'content-type' => 'application/json',
        //     'x-appwrite-project' => $this->getProject()['$id'],
        // ], $this->getHeaders()));

        // $this->assertEquals(200, $response['headers']['status-code']);
        // $this->assertEquals('verification', $response['body']['type']);
        // $this->assertEquals('en-us', $response['body']['locale']);
        // $this->assertEquals('Please verify your email {{token}}', $response['body']['message']);

        return $data;
    }

    /** @depends testCreateProject */
    public function testUpdateProjectAuthDuration($data): array
    {
        $id = $data['projectId'];

        // Check defaults
        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(TOKEN_EXPIRATION_LOGIN_LONG, $response['body']['authDuration']); // 1 Year

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $id . '/auth/duration', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'duration' => 60, // Set session duration to 1 minute
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

        // Set session duration to 15s
        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $id . '/auth/duration', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'duration' => 15, // seconds
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(15, $response['body']['authDuration']);

        // Create session
        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ]), [
            'email' => $userEmail,
            'password' => 'password',
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        $sessionCookie = $response['headers']['set-cookie'];

        // Wait 10 seconds, ensure valid session, extend session
        \sleep(10);

        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'Cookie' => $sessionCookie,
        ]));

        $this->assertEquals(200, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_PATCH, '/account/sessions/current', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => $sessionCookie,
        ]));

        $this->assertEquals(200, $response['headers']['status-code']);

        // Wait 20 seconds, ensure non-valid session
        \sleep(20);

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
            'duration' => TOKEN_EXPIRATION_LOGIN_LONG,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $projectId = $response['body']['$id'];

        // Check project is back to normal
        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $projectId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(TOKEN_EXPIRATION_LOGIN_LONG, $response['body']['authDuration']); // 1 Year

        return ['projectId' => $projectId];
    }

    public function testUpdateProjectInvalidateSessions(): void
    {
        // Create a team for the test project
        $team = $this->client->call(Client::METHOD_POST, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'teamId' => ID::unique(),
            'name' => 'Session Invalidation Test Team',
        ]);

        $this->assertEquals(201, $team['headers']['status-code']);

        // Create a test project
        $response = $this->client->call(Client::METHOD_POST, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'projectId' => ID::unique(),
            'name' => 'Session Invalidation Test Project',
            'teamId' => $team['body']['$id'],
            'region' => System::getEnv('_APP_REGION', 'default')
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $id = $response['body']['$id'];

        // Check defaults
        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertTrue($response['body']['authInvalidateSessions']);

        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $id . '/auth/session-invalidation', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'enabled' => false,
        ]);
        $this->assertFalse($response['body']['authInvalidateSessions']);

        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertFalse($response['body']['authInvalidateSessions']);

        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $id . '/auth/session-invalidation', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'enabled' => true,
        ]);
        $this->assertTrue($response['body']['authInvalidateSessions']);

        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertTrue($response['body']['authInvalidateSessions']);
    }

    public function testUpdateProjectOAuth(): void
    {
        // Create a team
        $team = $this->client->call(Client::METHOD_POST, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'teamId' => ID::unique(),
            'name' => 'Update Project OAuth Test Team',
        ]);

        $this->assertEquals(201, $team['headers']['status-code']);
        $teamId = $team['body']['$id'];

        // Create a project
        $response = $this->client->call(Client::METHOD_POST, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'projectId' => ID::unique(),
            'name' => 'Project Test',
            'teamId' => $teamId,
            'region' => System::getEnv('_APP_REGION', 'default')
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $id = $response['body']['$id'];

        $providers = require(__DIR__ . '/../../../../app/config/oAuthProviders.php');

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
            foreach ($response['body']['oAuthProviders'] as $responseProvider) {
                if ($responseProvider['key'] === $key) {
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
            foreach ($response['body']['oAuthProviders'] as $responseProvider) {
                if ($responseProvider['key'] === $key) {
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
    }

    public function testUpdateProjectAuthStatus(): void
    {
        // Create a team
        $team = $this->client->call(Client::METHOD_POST, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'teamId' => ID::unique(),
            'name' => 'Update Project Auth Status Test Team',
        ]);

        $this->assertEquals(201, $team['headers']['status-code']);
        $teamId = $team['body']['$id'];

        // Create a project
        $response = $this->client->call(Client::METHOD_POST, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'projectId' => ID::unique(),
            'name' => 'Project Test',
            'teamId' => $teamId,
            'region' => System::getEnv('_APP_REGION', 'default')
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $id = $response['body']['$id'];

        $auth = require(__DIR__ . '/../../../../app/config/auth.php');
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

        $session = $response['cookies']['a_session_' . $id];

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

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/anonymous', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $id,
        ]));

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
    }

    /**
     * @depends testCreateProject
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

        // Creating A Team
        $team = $this->client->call(Client::METHOD_POST, '/teams', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $id,
            'x-appwrite-mode' => 'admin',
        ], $this->getHeaders()), [
            'teamId' => ID::unique(),
            'name' => 'Test Team 1',
        ]);

        $this->assertEquals(201, $team['headers']['status-code']);

        $teamId = $team['body']['$id'];
        $email = uniqid() . 'user@localhost.test';

        // Creating A User Using Team membership
        $response = $this->client->call(Client::METHOD_POST, '/teams/' . $teamId . '/memberships', array_merge($this->getHeaders(), [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $id,
            'x-appwrite-mode' => 'admin',
        ]), [
            'email' => $email,
            'roles' => [],
            'url' => 'http://localhost',
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        $email = uniqid() . 'user@localhost.test';

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

        $this->assertEquals(Exception::USER_COUNT_EXCEEDED, $response['body']['type']);
        $this->assertEquals(400, $response['headers']['status-code']);


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

        $email = uniqid() . 'user@localhost.test';

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

        /**
         * List sessions
         */
        $this->assertEventually(function () use ($id, $sessionCookie, $sessionId2) {
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
        });

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


    /**
     * @depends testUpdateProjectAuthLimit
     */
    public function testUpdateProjectAuthPasswordHistory($data): array
    {
        $id = $data['projectId'] ?? '';

        /**
         * Test for Failure
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $id . '/auth/password-history', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'limit' => 25,
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);


        /**
         * Test for Success
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $id . '/auth/password-history', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'limit' => 1,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(1, $response['body']['authPasswordHistory']);


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

        $userId = $response['body']['$id'];

        // create session
        $session = $this->client->call(Client::METHOD_POST, '/account/sessions/email', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $id,
        ], [
            'email' => $email,
            'password' => $password,
        ]);
        $this->assertEquals(201, $session['headers']['status-code']);
        $session = $session['cookies']['a_session_' . $id];

        $response = $this->client->call(Client::METHOD_PATCH, '/account/password', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $id,
            'cookie' => 'a_session_' . $id . '=' . $session,
        ]), [
            'oldPassword' => $password,
            'password' => $password,
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $headers = array_merge($this->getHeaders(), [
            'x-appwrite-mode' => 'admin',
            'content-type' => 'application/json',
            'x-appwrite-project' => $id,
        ]);

        $response = $this->client->call(Client::METHOD_PATCH, '/users/' . $userId . '/password', $headers, [
            'password' => $password,
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);


        /**
         * Reset
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $id . '/auth/password-history', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'limit' => 0,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(0, $response['body']['authPasswordHistory']);
        return $data;
    }

    /**
     * @group smtpAndTemplates
     * @group projectsCRUD
     *
     * @depends testCreateProject
     * */
    public function testUpdateMockNumbers($data)
    {
        $id = $data['projectId'] ?? '';

        /**
         * Test for Failure
         */

        /** Trying to pass an empty body to the endpoint */
        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $id . '/auth/mock-numbers', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(400, $response['headers']['status-code']);
        $this->assertEquals('Param "numbers" is not optional.', $response['body']['message']);

        /** Trying to pass body with incorrect structure */
        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $id . '/auth/mock-numbers', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'numbers' => [
                'phone' => '+1655513432',
                'otp' => '123456'
            ]
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);
        $this->assertEquals('Invalid `numbers` param: Value must a valid array no longer than 10 items and Invalid payload structure. Please check the "phone" and "otp" fields', $response['body']['message']);

        /** Trying to pass an OTP longer than 6 characters*/
        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $id . '/auth/mock-numbers', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'numbers' => [
                [
                    'phone' => '+1655513432',
                    'otp' => '12345678'
                ]
            ]
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);
        $this->assertEquals('Invalid `numbers` param: Value must a valid array no longer than 10 items and Invalid OTP. Please make sure the OTP is a 6 digit number', $response['body']['message']);

        /** Trying to pass an OTP shorter than 6 characters*/
        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $id . '/auth/mock-numbers', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'numbers' => [
                [
                    'phone' => '+1655513432',
                    'otp' => '123'
                ]
            ]
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);
        $this->assertEquals('Invalid `numbers` param: Value must a valid array no longer than 10 items and Invalid OTP. Please make sure the OTP is a 6 digit number', $response['body']['message']);

        /** Trying to pass an OTP with non numeric characters */
        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $id . '/auth/mock-numbers', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'numbers' => [
                [
                    'phone' => '+1655513432',
                    'otp' => '123re2'
                ]
            ]
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);
        $this->assertEquals('Invalid `numbers` param: Value must a valid array no longer than 10 items and Invalid OTP. Please make sure the OTP is a 6 digit number', $response['body']['message']);

        /** Trying to pass an invalid phone number  */
        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $id . '/auth/mock-numbers', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'numbers' => [
                [
                    'phone' => '1655234',
                    'otp' => '123456'
                ]
            ]
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);
        $this->assertEquals('Invalid `numbers` param: Value must a valid array no longer than 10 items and Phone number must start with a \'+\' can have a maximum of fifteen digits.', $response['body']['message']);

        /** Trying to pass a number longer than 15 digits  */
        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $id . '/auth/mock-numbers', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'numbers' => [
                [
                    'phone' => '+1234567890987654',
                    'otp' => '123456'
                ]
            ]
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);
        $this->assertEquals('Invalid `numbers` param: Value must a valid array no longer than 10 items and Phone number must start with a \'+\' can have a maximum of fifteen digits.', $response['body']['message']);

        /** Trying to pass duplicate numbers  */
        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $id . '/auth/mock-numbers', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'numbers' => [
                [
                    'phone' => '+1655513432',
                    'otp' => '123456'
                ],
                [
                    'phone' => '+1655513432',
                    'otp' => '123456'
                ]
            ]
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);
        $this->assertEquals('Duplicate phone numbers are not allowed.', $response['body']['message']);

        $numbers = [];
        for ($i = 0; $i < 11; $i++) {
            $numbers[] = [
                'phone' => '+1655513432',
                'otp' => '123456'
            ];
        }

        /** Trying to pass more than 10 values */
        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $id . '/auth/mock-numbers', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'numbers' => $numbers
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
        $this->assertEquals('Invalid `numbers` param: Value must a valid array no longer than 10 items and Phone number must start with a \'+\' can have a maximum of fifteen digits.', $response['body']['message']);

        /**
         * Test for success
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $id . '/auth/mock-numbers', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'numbers' => []
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $id . '/auth/mock-numbers', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'numbers' => [
                [
                    'phone' => '+1655513432',
                    'otp' => '123456'
                ]
            ]
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);

        // Create phone session for this project and check if the mock number is used
        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/phone', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $id,
        ]), [
            'userId' => 'unique()',
            'phone' => '+1655513432',
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $userId = $response['body']['userId'];

        $response = $this->client->call(Client::METHOD_PUT, '/account/sessions/phone', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $id,
        ]), [
            'userId' => $userId,
            'secret' => '654321', // Try a random code
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_PUT, '/account/sessions/phone', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $id,
        ]), [
            'userId' => $userId,
            'secret' => '123456',
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
    }

    /**
     * @depends testUpdateProjectAuthLimit
     */
    public function testUpdateProjectAuthPasswordDictionary($data): array
    {
        $id = $data['projectId'] ?? '';

        $password = 'password';
        $name = 'User Name';

        /**
         * Test for Success
         */

        /**
         * create account
         */
        $response = $this->client->call(Client::METHOD_POST, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $id,
        ]), [
            'userId' => ID::unique(),
            'email' => uniqid() . 'user@localhost.test',
            'password' => $password,
            'name' => $name,
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);
        $userId = $response['body']['$id'];

        /**
         * Create user
         */
        $user = $this->client->call(Client::METHOD_POST, '/users', array_merge($this->getHeaders(), [
            'content-type' => 'application/json',
            'x-appwrite-project' => $id,
            'x-appwrite-mode' => 'admin',
        ]), [
            'userId' => ID::unique(),
            'email' => uniqid() . 'user@localhost.test',
            'password' => 'password',
            'name' => 'Cristiano Ronaldo',
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);

        /**
         * Enable Disctionary
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $id . '/auth/password-dictionary', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'enabled' => true,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(true, $response['body']['authPasswordDictionary']);

        /**
         * Test for failure
         */
        $response = $this->client->call(Client::METHOD_POST, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $id,
        ]), [
            'userId' => ID::unique(),
            'email' => uniqid() . 'user@localhost.test',
            'password' => $password,
            'name' => $name,
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        /**
         * Create user
         */
        $user = $this->client->call(Client::METHOD_POST, '/users', array_merge($this->getHeaders(), [
            'content-type' => 'application/json',
            'x-appwrite-project' => $id,
            'x-appwrite-mode' => 'admin',
        ]), [
            'userId' => ID::unique(),
            'email' => uniqid() . 'user@localhost.test',
            'password' => 'password',
            'name' => 'Cristiano Ronaldo',
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        $headers = array_merge($this->getHeaders(), [
            'x-appwrite-mode' => 'admin',
            'content-type' => 'application/json',
            'x-appwrite-project' => $id,
        ]);

        $response = $this->client->call(Client::METHOD_PATCH, '/users/' . $userId . '/password', $headers, [
            'password' => $password,
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);


        /**
         * Reset
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $id . '/auth/password-history', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'limit' => 0,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(0, $response['body']['authPasswordHistory']);

        /**
         * Reset
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $id . '/auth/password-dictionary', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'enabled' => false,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(false, $response['body']['authPasswordDictionary']);

        return $data;
    }

    /**
     * @depends testCreateProject
     */
    public function testUpdateDisallowPersonalData($data): void
    {
        $id = $data['projectId'] ?? '';

        /**
         * Enable Disallowing of Personal Data
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $id . '/auth/personal-data', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'enabled' => true,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(true, $response['body']['authPersonalDataCheck']);

        /**
         * Test for failure
         */
        $email = uniqid() . 'user@localhost.test';
        $password = 'password';
        $name = 'username';
        $userId = ID::unique();

        $response = $this->client->call(Client::METHOD_POST, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $id,
        ]), [
            'email' => $email,
            'password' => $email,
            'name' => $name,
            'userId' => $userId
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
        $this->assertEquals(400, $response['body']['code']);
        $this->assertEquals(Exception::USER_PASSWORD_PERSONAL_DATA, $response['body']['type']);

        $response = $this->client->call(Client::METHOD_POST, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $id,
        ]), [
            'email' => $email,
            'password' => $name,
            'name' => $name,
            'userId' => $userId
        ]);

        $phone = '+123456789';
        $response = $this->client->call(Client::METHOD_POST, '/users', array_merge($this->getHeaders(), [
            'content-type' => 'application/json',
            'x-appwrite-project' => $id,
            'x-appwrite-mode' => 'admin',
        ]), [
            'email' => $email,
            'password' => $phone,
            'name' => $name,
            'userId' => $userId,
            'phone' => $phone
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
        $this->assertEquals(400, $response['body']['code']);
        $this->assertEquals(Exception::USER_PASSWORD_PERSONAL_DATA, $response['body']['type']);

        /** Test for success */
        $email = uniqid() . 'user@localhost.test';
        $password = 'password';
        $name = 'username';
        $userId = ID::unique();
        $response = $this->client->call(Client::METHOD_POST, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $id,
        ]), [
            'email' => $email,
            'password' => $password,
            'name' => $name,
            'userId' => $userId
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/users', array_merge($this->getHeaders(), [
            'content-type' => 'application/json',
            'x-appwrite-project' => $id,
            'x-appwrite-mode' => 'admin',
        ]), [
            // Empty password
            'email' => uniqid() . 'user@localhost.test',
            'name' => 'User',
            'userId' => ID::unique(),
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        $email = uniqid() . 'user@localhost.test';
        $userId = ID::unique();
        $response = $this->client->call(Client::METHOD_POST, '/users', array_merge($this->getHeaders(), [
            'content-type' => 'application/json',
            'x-appwrite-project' => $id,
            'x-appwrite-mode' => 'admin',
        ]), [
            'email' => $email,
            'password' => $password,
            'name' => $name,
            'userId' => $userId,
            'phone' => $phone
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);


        /**
         * Reset
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $id . '/auth/personal-data', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'enabled' => false,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(false, $response['body']['authPersonalDataCheck']);
    }

    public function testUpdateProjectServicesAll(): void
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
            'region' => System::getEnv('_APP_REGION', 'default')
        ]);

        $this->assertEquals(201, $project['headers']['status-code']);
        $this->assertNotEmpty($project['body']['$id']);

        $id = $project['body']['$id'];

        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $id . '/service/all', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ]), [
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

        $matches = [];
        $pattern = '/serviceStatusFor.*/';

        foreach ($response['body'] as $key => $value) {
            if (\preg_match($pattern, $key)) {
                $matches[$key] = $value;
            }
        }

        foreach ($matches as $value) {
            $this->assertFalse($value);
        }

        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $id . '/service/all', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ]), [
            'status' => true,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);

        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ]));

        $this->assertEquals(200, $response['headers']['status-code']);

        $matches = [];
        foreach ($response['body'] as $key => $value) {
            if (\preg_match($pattern, $key)) {
                $matches[$key] = $value;
            }
        }

        foreach ($matches as $value) {
            $this->assertTrue($value);
        }
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
            'region' => System::getEnv('_APP_REGION', 'default')
        ]);

        $this->assertEquals(201, $project['headers']['status-code']);
        $this->assertNotEmpty($project['body']['$id']);

        $id = $project['body']['$id'];
        $services = require(__DIR__ . '/../../../../app/config/services.php');

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

        $services = require(__DIR__ . '/../../../../app/config/services.php');

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

        $this->assertEquals(403, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $id,
        ]), [
            'teamId' => ID::unique(),
            'name' => 'Arsenal'
        ]);

        $this->assertEquals(403, $response['headers']['status-code']);

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

        $services = require(__DIR__ . '/../../../../app/config/services.php');

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
        $projectId = $data['projectId'] ?? '';
        $teamId = $data['teamId'] ?? '';

        /**
         * Test for SUCCESS
         */

        // Expiring key
        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $projectId . '/keys', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Key Test',
            'scopes' => ['users.write'],
            'expire' => DateTime::addSeconds(new \DateTime(), 3600),
        ]);

        $response = $this->client->call(Client::METHOD_POST, '/users', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $response['body']['secret']
        ], [
            'userId' => ID::unique(),
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        // No expiry
        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $projectId . '/keys', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Key Test',
            'scopes' => ['health.read'],
            'expire' => null,
        ]);

        $response = $this->client->call(Client::METHOD_GET, '/health', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $response['body']['secret']
        ], []);

        $this->assertEquals(200, $response['headers']['status-code']);

        /**
         * Test for FAILURE
         */

        // Expired key
        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $projectId . '/keys', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Key Test',
            'scopes' => ['health.read'],
            'expire' => DateTime::addSeconds(new \DateTime(), -3600),
        ]);

        $response = $this->client->call(Client::METHOD_GET, '/health', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $response['body']['secret']
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);

        // Invalid key
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-mode' => 'admin',
        ], $this->getHeaders()), [
            'bucketId' => ID::unique(),
            'name' => 'Test Bucket',
        ]);

        $this->assertEquals(201, $bucket['headers']['status-code']);
        $this->assertNotEmpty($bucket['body']['$id']);

        $bucketId = $bucket['body']['$id'];

        $response = $this->client->call(Client::METHOD_GET, "/storage/buckets/{$bucketId}/files", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => 'invalid-key'
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);

        // Invalid scopes
        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $projectId . '/keys', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Key Test',
            'scopes' => ['teams.read'],
            'expire' => DateTime::addSeconds(new \DateTime(), 3600),
        ]);

        $response = $this->client->call(Client::METHOD_GET, '/users', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $response['body']['secret']
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);

        // Invalid key from different project
        $response = $this->client->call(Client::METHOD_POST, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'projectId' => ID::unique(),
            'name' => 'Project Test 2',
            'teamId' => $teamId,
            'region' => System::getEnv('_APP_REGION', 'default')
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        $project2Id = $response['body']['$id'];

        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $project2Id . '/keys', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Key Test',
            'scopes' => ['health.read'],
            'expire' => DateTime::addSeconds(new \DateTime(), 3600),
        ]);

        $response = $this->client->call(Client::METHOD_GET, '/health', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $response['body']['secret']
        ]);

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
            'scopes' => ['users.read', 'users.write', 'collections.read', 'tables.read'],
            'expire' => DateTime::addSeconds(new \DateTime(), 360),
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals($keyId, $response['body']['$id']);
        $this->assertEquals('Key Test Update', $response['body']['name']);
        $this->assertContains('users.read', $response['body']['scopes']);
        $this->assertContains('users.write', $response['body']['scopes']);
        $this->assertContains('collections.read', $response['body']['scopes']);
        $this->assertContains('tables.read', $response['body']['scopes']);
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
        $this->assertContains('tables.read', $response['body']['scopes']);
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

    /**
     * @depends testCreateProject
     */
    public function testCreateProjectKeyOutdated($data): void
    {
        $id = $data['projectId'] ?? '';

        $response = $this->client->call(Client::METHOD_POST, '/mock/api-key-unprefixed', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'projectId' => $id
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertContains('users.read', $response['body']['scopes']);
        $this->assertNotEmpty($response['body']['secret']);
        $this->assertStringStartsNotWith(API_KEY_STANDARD . '_', $response['body']['secret']);

        $keyId = $response['body']['$id'];
        $secret = $response['body']['secret'];

        $response = $this->client->call(Client::METHOD_GET, '/users', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $id,
            'x-appwrite-key' => $secret
        ], []);

        $this->assertEquals(200, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_DELETE, '/projects/' . $id . '/keys/' . $keyId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(204, $response['headers']['status-code']);
        $this->assertEmpty($response['body']);
    }

    // JWT Keys

    /**
     * @depends testCreateProject
     */
    public function testJWTKey($data): void
    {
        $id = $data['projectId'] ?? '';

        // Create JWT key
        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $id . '/jwts', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'duration' => 5,
            'scopes' => ['users.read'],
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['jwt']);

        $jwt = $response['body']['jwt'];

        // Ensure JWT key works
        $response = $this->client->call(Client::METHOD_GET, '/users', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $id,
            'x-appwrite-key' => $jwt,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertArrayHasKey('users', $response['body']);

        // Ensure JWT key respect scopes
        $response = $this->client->call(Client::METHOD_GET, '/functions', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $id,
            'x-appwrite-key' => $jwt,
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);

        // Ensure JWT key expires
        \sleep(10);

        $response = $this->client->call(Client::METHOD_GET, '/users', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $id,
            'x-appwrite-key' => $jwt,
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);
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

        $this->assertEventually(function () use ($id) {
            $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id . '/platforms', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()), []);

            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertEquals(8, $response['body']['total']);
        });

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

    public function testDeleteProject(): array
    {
        $data = [];

        // Create a team and a project
        $team = $this->client->call(Client::METHOD_POST, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'teamId' => ID::unique(),
            'name' => 'Amazing Team',
        ]);

        $this->assertEquals(201, $team['headers']['status-code']);
        $this->assertEquals('Amazing Team', $team['body']['name']);
        $this->assertNotEmpty($team['body']['$id']);

        $teamId = $team['body']['$id'];

        $project = $this->client->call(Client::METHOD_POST, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'projectId' => ID::unique(),
            'name' => 'Amazing Project',
            'teamId' => $teamId,
            'region' => System::getEnv('_APP_REGION', 'default')
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

        // Delete Project
        $project = $this->client->call(Client::METHOD_DELETE, '/projects/' . $projectId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(204, $project['headers']['status-code']);

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

    public function testDeleteSharedProject(): void
    {
        $team = $this->client->call(Client::METHOD_POST, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'teamId' => ID::unique(),
            'name' => 'Amazing Team',
        ]);

        $teamId = $team['body']['$id'];

        // Ensure deleting one project does not affect another project
        $project1 = $this->client->call(Client::METHOD_POST, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'projectId' => ID::unique(),
            'name' => 'Amazing Project 1',
            'teamId' => $teamId,
            'region' => System::getEnv('_APP_REGION', 'default')
        ]);

        $project2 = $this->client->call(Client::METHOD_POST, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'projectId' => ID::unique(),
            'name' => 'Amazing Project 2',
            'teamId' => $teamId,
            'region' => System::getEnv('_APP_REGION', 'default')
        ]);

        $project1Id = $project1['body']['$id'];
        $project2Id = $project2['body']['$id'];

        // Create user in each project
        $key1 = $this->client->call(Client::METHOD_POST, '/projects/' . $project1Id . '/keys', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Key Test',
            'scopes' => ['users.read', 'users.write'],
        ]);

        $user1 = $this->client->call(Client::METHOD_POST, '/users', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $project1Id,
            'x-appwrite-key' => $key1['body']['secret'],
        ], [
            'userId' => ID::unique(),
            'email' => 'test1@appwrite.io',
            'password' => 'password',
        ]);

        $this->assertEquals(201, $user1['headers']['status-code']);

        $key2 = $this->client->call(Client::METHOD_POST, '/projects/' . $project2Id . '/keys', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Key Test',
            'scopes' => ['users.read', 'users.write'],
        ]);

        $user2 = $this->client->call(Client::METHOD_POST, '/users', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $project2Id,
            'x-appwrite-key' => $key2['body']['secret'],
        ], [
            'userId' => ID::unique(),
            'email' => 'test2@appwrite.io',
            'password' => 'password',
        ]);

        $this->assertEquals(201, $user2['headers']['status-code']);

        // Delete project 1
        $project1 = $this->client->call(Client::METHOD_DELETE, '/projects/' . $project1Id, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(204, $project1['headers']['status-code']);

        // Ensure project 2 user is still there
        $this->assertEventually(function () use ($user2, $project2Id, $key2) {
            $response = $this->client->call(Client::METHOD_GET, '/users/' . $user2['body']['$id'], [
                'content-type' => 'application/json',
                'x-appwrite-project' => $project2Id,
                'x-appwrite-key' => $key2['body']['secret'],
            ]);

            $this->assertEquals(200, $response['headers']['status-code']);
        });

        // Create another user in project 2 in case read hits stale cache
        $user3 = $this->client->call(Client::METHOD_POST, '/users', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $project2Id,
            'x-appwrite-key' => $key2['body']['secret'],
        ], [
            'userId' => ID::unique(),
            'email' => 'test3@appwrite.io'
        ]);

        $this->assertEquals(201, $user3['headers']['status-code']);
    }

    /**
     * @depends testCreateProject
     */
    public function testCreateProjectVariable(array $data)
    {
        /**
         * Test for SUCCESS
         */
        $variable = $this->client->call(Client::METHOD_POST, '/project/variables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $data['projectId'],
            'x-appwrite-mode' => 'admin',
        ], $this->getHeaders()), [
            'key' => 'APP_TEST',
            'value' => 'TESTINGVALUE',
            'secret' => false
        ]);

        $this->assertEquals(201, $variable['headers']['status-code']);
        $this->assertEquals('APP_TEST', $variable['body']['key']);
        $this->assertEquals('TESTINGVALUE', $variable['body']['value']);
        $this->assertFalse($variable['body']['secret']);
        $variableId = $variable['body']['$id'];

        // test for secret variable
        $variable = $this->client->call(Client::METHOD_POST, '/project/variables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $data['projectId'],
            'x-appwrite-mode' => 'admin',
        ], $this->getHeaders()), [
            'key' => 'APP_TEST_1',
            'value' => 'TESTINGVALUE_1',
            'secret' => true
        ]);

        $this->assertEquals(201, $variable['headers']['status-code']);
        $this->assertEquals('APP_TEST_1', $variable['body']['key']);
        $this->assertEmpty($variable['body']['value']);

        $secretVariableId = $variable['body']['$id'];

        /**
         * Test for FAILURE
         */
        // Test for duplicate key
        $variable = $this->client->call(Client::METHOD_POST, '/project/variables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $data['projectId'],
            'x-appwrite-mode' => 'admin',
        ], $this->getHeaders()), [
            'key' => 'APP_TEST',
            'value' => 'ANOTHERTESTINGVALUE'
        ]);

        $this->assertEquals(409, $variable['headers']['status-code']);

        // Test for invalid key
        $variable = $this->client->call(Client::METHOD_POST, '/project/variables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $data['projectId'],
            'x-appwrite-mode' => 'admin',
        ], $this->getHeaders()), [
            'key' => str_repeat("A", 256),
            'value' => 'TESTINGVALUE'
        ]);

        $this->assertEquals(400, $variable['headers']['status-code']);

        // Test for invalid value
        $variable = $this->client->call(Client::METHOD_POST, '/project/variables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $data['projectId'],
            'x-appwrite-mode' => 'admin',
        ], $this->getHeaders()), [
            'key' => 'LONGKEY',
            'value' => str_repeat("#", 8193),
        ]);

        $this->assertEquals(400, $variable['headers']['status-code']);

        return array_merge(
            $data,
            [
                'variableId' => $variableId,
                'secretVariableId' => $secretVariableId
            ]
        );
    }

    /**
     * @depends testCreateProjectVariable
     */
    public function testListVariables(array $data)
    {
        /**
         * Test for SUCCESS
         */

        $response = $this->client->call(Client::METHOD_GET, '/project/variables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $data['projectId'],
            'x-appwrite-mode' => 'admin',
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(2, $response['body']['variables']);
        $this->assertEquals(2, $response['body']['total']);
        $this->assertEquals("APP_TEST", $response['body']['variables'][0]['key']);
        $this->assertEquals("TESTINGVALUE", $response['body']['variables'][0]['value']);
        $this->assertEquals("APP_TEST_1", $response['body']['variables'][1]['key']);
        $this->assertEmpty($response['body']['variables'][1]['value']);

        return $data;
    }

    /**
     * @depends testListVariables
     */
    public function testGetVariable(array $data)
    {
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/project/variables/' . $data['variableId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $data['projectId'],
            'x-appwrite-mode' => 'admin',
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals("APP_TEST", $response['body']['key']);
        $this->assertEquals("TESTINGVALUE", $response['body']['value']);

        $response = $this->client->call(Client::METHOD_GET, '/project/variables/' . $data['secretVariableId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $data['projectId'],
            'x-appwrite-mode' => 'admin',
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals("APP_TEST_1", $response['body']['key']);
        $this->assertEmpty($response['body']['value']);
        $this->assertTrue($response['body']['secret']);

        /**
         * Test for FAILURE
         */

        $response = $this->client->call(Client::METHOD_GET, '/project/variables/NON_EXISTING_VARIABLE', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $data['projectId'],
            'x-appwrite-mode' => 'admin',
        ], $this->getHeaders()));

        $this->assertEquals(404, $response['headers']['status-code']);

        return $data;
    }

    /**
     * @depends testGetVariable
     */
    public function testUpdateVariable(array $data)
    {
        /**
         * Test for SUCCESS
         */

        $response = $this->client->call(Client::METHOD_PUT, '/project/variables/' . $data['variableId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $data['projectId'],
            'x-appwrite-mode' => 'admin',
        ], $this->getHeaders()), [
            'key' => 'APP_TEST_UPDATE',
            'value' => 'TESTINGVALUEUPDATED'
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals("APP_TEST_UPDATE", $response['body']['key']);
        $this->assertEquals("TESTINGVALUEUPDATED", $response['body']['value']);

        $variable = $this->client->call(Client::METHOD_GET, '/project/variables/' . $data['variableId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $data['projectId'],
            'x-appwrite-mode' => 'admin',
        ], $this->getHeaders()));

        $this->assertEquals(200, $variable['headers']['status-code']);
        $this->assertEquals("APP_TEST_UPDATE", $variable['body']['key']);
        $this->assertEquals("TESTINGVALUEUPDATED", $variable['body']['value']);

        $response = $this->client->call(Client::METHOD_PUT, '/project/variables/' . $data['secretVariableId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $data['projectId'],
            'x-appwrite-mode' => 'admin',
        ], $this->getHeaders()), [
            'key' => 'APP_TEST_UPDATE_1',
            'value' => 'TESTINGVALUEUPDATED_1'
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals("APP_TEST_UPDATE_1", $response['body']['key']);
        $this->assertEmpty($response['body']['value']);

        $variable = $this->client->call(Client::METHOD_GET, '/project/variables/' . $data['secretVariableId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $data['projectId'],
            'x-appwrite-mode' => 'admin',
        ], $this->getHeaders()));

        $this->assertEquals(200, $variable['headers']['status-code']);
        $this->assertEquals("APP_TEST_UPDATE_1", $variable['body']['key']);
        $this->assertEmpty($variable['body']['value']);

        $response = $this->client->call(Client::METHOD_PUT, '/project/variables/' . $data['secretVariableId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $data['projectId'],
            'x-appwrite-mode' => 'admin',
        ], $this->getHeaders()), [
            'key' => 'APP_TEST_UPDATE_1',
            'secret' => false,
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/project/variables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $data['projectId'],
            'x-appwrite-mode' => 'admin',
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(2, $response['body']['variables']);
        $this->assertEquals("APP_TEST_UPDATE", $response['body']['variables'][0]['key']);
        $this->assertEquals("APP_TEST_UPDATE_1", $response['body']['variables'][1]['key']);

        /**
         * Test for FAILURE
         */

        $response = $this->client->call(Client::METHOD_PUT, '/project/variables/' . $data['variableId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $data['projectId'],
            'x-appwrite-mode' => 'admin',
        ], $this->getHeaders()));

        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_PUT, '/project/variables/' . $data['variableId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $data['projectId'],
            'x-appwrite-mode' => 'admin',
        ], $this->getHeaders()), [
            'value' => 'TESTINGVALUEUPDATED_2'
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $longKey = str_repeat("A", 256);
        $response = $this->client->call(Client::METHOD_PUT, '/project/variables/' . $data['variableId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $data['projectId'],
            'x-appwrite-mode' => 'admin',
        ], $this->getHeaders()), [
            'key' => $longKey,
            'value' => 'TESTINGVALUEUPDATED'
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $longValue = str_repeat("#", 8193);
        $response = $this->client->call(Client::METHOD_PUT, '/project/variables/' . $data['variableId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $data['projectId'],
            'x-appwrite-mode' => 'admin',
        ], $this->getHeaders()), [
            'key' => 'APP_TEST_UPDATE',
            'value' => $longValue
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_PUT, '/project/variables/NON_EXISTING_VARIABLE', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $data['projectId'],
            'x-appwrite-mode' => 'admin',
        ], $this->getHeaders()), [
            'key' => 'APP_TEST_UPDATE',
            'value' => 'TESTINGVALUEUPDATED'
        ]);

        $this->assertEquals(404, $response['headers']['status-code']);

        return $data;
    }

    /**
     * @depends testUpdateVariable
     */
    public function testDeleteVariable(array $data)
    {
        /**
         * Test for SUCCESS
         */

        $response = $this->client->call(Client::METHOD_DELETE, '/project/variables/' . $data['variableId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $data['projectId'],
            'x-appwrite-mode' => 'admin',
        ], $this->getHeaders()));

        $this->assertEquals(204, $response['headers']['status-code']);

        $this->assertEquals(204, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_DELETE, '/project/variables/' . $data['secretVariableId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $data['projectId'],
            'x-appwrite-mode' => 'admin',
        ], $this->getHeaders()));

        $response = $this->client->call(Client::METHOD_GET, '/project/variables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $data['projectId'],
            'x-appwrite-mode' => 'admin',
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(0, $response['body']['variables']);
        $this->assertEquals(0, $response['body']['total']);

        /**
         * Test for FAILURE
         */

        $response = $this->client->call(Client::METHOD_DELETE, '/project/variables/NON_EXISTING_VARIABLE', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $data['projectId'],
            'x-appwrite-mode' => 'admin',
        ], $this->getHeaders()));

        $this->assertEquals(404, $response['headers']['status-code']);

        return $data;
    }

    /**
     * Devkeys Tests starts here ------------------------------------------------
     */

    /**
     * @group devKeys
     */
    public function testCreateProjectDevKey(): void
    {
        /**
         * Test for SUCCESS
         */
        $id = $this->setupProject([
            'projectId' => ID::unique(),
            'name' => 'testCreateProjectDevKey',
            'region' => System::getEnv('_APP_REGION', 'default')
        ]);

        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $id . '/dev-keys', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Key Test',
            'expire' => DateTime::addSeconds(new \DateTime(), 36000)
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals('Key Test', $response['body']['name']);
        $this->assertNotEmpty($response['body']['secret']);
        $this->assertArrayHasKey('accessedAt', $response['body']);
        $this->assertEmpty($response['body']['accessedAt']);

        /** Create a second dev key */
        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $id . '/dev-keys', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Dev Key Test',
            'expire' => DateTime::addSeconds(new \DateTime(), 36000)
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals('Dev Key Test', $response['body']['name']);
        $this->assertNotEmpty($response['body']['secret']);
        $this->assertArrayHasKey('accessedAt', $response['body']);
        $this->assertEmpty($response['body']['accessedAt']);

        /**
         * Test for FAILURE
         */

        /** TEST expiry date is required */
        $res = $this->client->call(Client::METHOD_POST, '/projects/' . $id . '/dev-keys', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Key Test'
        ]);

        $this->assertEquals(400, $res['headers']['status-code']);
    }


    /**
     * @group devKeys
     */
    public function testListProjectDevKey(): void
    {
        /**
         * Test for SUCCESS
         */
        $projectId = $this->setupProject([
            'projectId' => ID::unique(),
            'name' => 'testListProjectDevKey',
            'region' => System::getEnv('_APP_REGION', 'default')
        ]);

        /** Create devKey 1 */
        $this->setupDevKey([
            'projectId' => $projectId,
            'name' => 'Key Test',
            'expire' => DateTime::addSeconds(new \DateTime(), 36000)
        ]);

        /** Create devKey 2 */
        $this->setupDevKey([
            'projectId' => $projectId,
            'name' => 'Dev Key Test',
            'expire' => DateTime::addSeconds(new \DateTime(), 36000)
        ]);

        /** List all dev keys */
        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $projectId . '/dev-keys', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(2, $response['body']['total']);

        /** List dev keys with limit */
        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $projectId . '/dev-keys', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::limit(1)->toString()
            ]
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(1, $response['body']['total']);

        /** List dev keys with search */
        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $projectId . '/dev-keys', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(2, $response['body']['total']);
        $this->assertEquals('Key Test', $response['body']['devKeys'][0]['name']);
        $this->assertEquals('Dev Key Test', $response['body']['devKeys'][1]['name']);

        /** List dev keys with querying `expire` */
        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $projectId . '/dev-keys', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::lessThan('expire', (new \DateTime())->format('Y-m-d H:i:s'))->toString()]
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(0, $response['body']['total']); // No dev keys expired

        /**
         * Test for FAILURE
         */

        /** Test for search with invalid query */
        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $projectId . '/dev-keys', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::search('name', 'Invalid')->toString()
            ]
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
        $this->assertEquals('Invalid `queries` param: Invalid query: Attribute not found in schema: name', $response['body']['message']);
    }


    /**
     * @group devKeys
     */
    public function testGetProjectDevKey(): void
    {
        /**
         * Test for SUCCESS
         */
        $projectId = $this->setupProject([
            'projectId' => ID::unique(),
            'name' => 'testGetProjectDevKey',
            'region' => System::getEnv('_APP_REGION', 'default')
        ]);

        $devKey = $this->setupDevKey([
            'projectId' => $projectId,
            'name' => 'Dev Key Test',
            'expire' => DateTime::addSeconds(new \DateTime(), 36000)
        ]);

        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $projectId . '/dev-keys/' . $devKey['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals($devKey['$id'], $response['body']['$id']);
        $this->assertEquals('Dev Key Test', $response['body']['name']);
        $this->assertNotEmpty($response['body']['secret']);
        $this->assertArrayHasKey('accessedAt', $response['body']);
        $this->assertEmpty($response['body']['accessedAt']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $projectId . '/dev-keys/error', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(404, $response['headers']['status-code']);
    }

    /**
     * @group devKeys
     */
    public function testGetDevKeyWithSdks(): void
    {
        /**
         * Test for SUCCESS
         */
        $projectId = $this->setupProject([
            'projectId' => ID::unique(),
            'name' => 'testGetDevKeyWithSdks',
            'region' => System::getEnv('_APP_REGION', 'default')
        ]);

        $devKey = $this->setupDevKey([
            'projectId' => $projectId,
            'name' => 'Dev Key Test',
            'expire' => DateTime::addSeconds(new \DateTime(), 36000)
        ]);

        /** Use dev key with python sdk */
        $res = $this->client->call(Client::METHOD_POST, '/account/sessions/email', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-dev-key' => $devKey['secret'],
            'x-sdk-name' => 'python'
        ], [
            'email' => 'user@appwrite.io',
            'password' => 'password'
        ]);
        $this->assertEquals(401, $res['headers']['status-code']);

        /** Use dev key with php sdk */
        $res = $this->client->call(Client::METHOD_POST, '/account/sessions/email', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-dev-key' => $devKey['secret'],
            'x-sdk-name' => 'php'
        ], [
            'email' => 'user@appwrite.io',
            'password' => 'password'
        ]);
        $this->assertEquals(401, $res['headers']['status-code']);

        /** Get the dev key */
        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $projectId . '/dev-keys/' . $devKey['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertArrayHasKey('sdks', $response['body']);
        $this->assertCount(2, $response['body']['sdks']);
        $this->assertContains('python', $response['body']['sdks']);
        $this->assertContains('php', $response['body']['sdks']);
    }

    /**
     * @group devKeys
     */
    public function testNoHostValidationWithDevKey(): void
    {
        /**
         * Test for SUCCESS
         */
        $projectId = $this->setupProject([
            'projectId' => ID::unique(),
            'name' => 'testNoHostValidationWithDevKey',
            'region' => System::getEnv('_APP_REGION', 'default')
        ]);

        $devKey = $this->setupDevKey([
            'projectId' => $projectId,
            'name' => 'Dev Key Test',
            'expire' => DateTime::addSeconds(new \DateTime(), 36000)
        ]);

        $provider = 'mock';
        $appId = '1';
        $secret = '123456';

        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $projectId . '/oauth2', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'provider' => $provider,
            'appId' => $appId,
            'secret' => $secret,
            'enabled' => true,
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);

        /** Test oauth2 and get invalid `success` URL */
        $response = $this->client->call(Client::METHOD_GET, '/account/sessions/oauth2/' . $provider, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], [
            'success' => 'https://example.com',
            'failure' => 'https://example.com'
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        /** Test oauth2 with devKey and now get oauth2 is disabled */
        $response = $this->client->call(Client::METHOD_GET, '/account/sessions/oauth2/' . $provider, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-dev-key' => $devKey['secret']
        ], [
            'success' => 'https://example.com',
            'failure' => 'https://example.com'
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);

        /** Test hostname in Magic URL */
        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/magic-url', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], [
            'userId' => ID::unique(),
            'email' => 'user@appwrite.io',
            'url' => 'https://example.com',
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        /** Test hostname in Magic URL with devKey */
        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/magic-url', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-dev-key' => $devKey['secret']
        ], [
            'userId' => ID::unique(),
            'email' => 'user@appwrite.io',
            'url' => 'https://example.com',
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);
    }

    /**
     * @group devKeys
     */
    public function testCorsWithDevKey(): void
    {
        /**
         * Test for SUCCESS
         */
        $projectId = $this->setupProject([
            'projectId' => ID::unique(),
            'name' => 'testCorsWithDevKey',
            'region' => System::getEnv('_APP_REGION', 'default')
        ]);

        $devKey = $this->setupDevKey([
            'projectId' => $projectId,
            'name' => 'Dev Key Test',
            'expire' => DateTime::addSeconds(new \DateTime(), 36000)
        ]);

        $origin = 'http://example.com';

        /**
         * Test CORS without Dev Key (should fail due to origin)
         */
        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', [
            'origin' => $origin,
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], [
            'email' => 'user@appwrite.io',
            'password' => 'password'
        ]);

        $this->assertEquals(403, $response['headers']['status-code']);
        $this->assertNotEquals($origin, $response['headers']['access-control-allow-origin'] ?? null);
        // you should not return a fallback origin for a disallowed host
        $this->assertNull($response['headers']['access-control-allow-origin'] ?? null);


        /**
         * Test CORS with Dev Key (should bypass origin check)
         */
        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', [
            'origin' => $origin,
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-dev-key' => $devKey['secret']
        ], [
            'email' => 'user@appwrite.io',
            'password' => 'password'
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);
        $this->assertEquals($origin, $response['headers']['access-control-allow-origin'] ?? null);
    }

    /**
     * @group devKeys
     */
    public function testNoRateLimitWithDevKey(): void
    {
        /**
         * Test for SUCCESS
         */
        $projectId = $this->setupProject([
            'projectId' => ID::unique(),
            'name' => 'testNoRateLimitWithDevKey',
            'region' => System::getEnv('_APP_REGION', 'default')
        ]);

        $devKey = $this->setupDevKey([
            'projectId' => $projectId,
            'name' => 'Dev Key Test',
            'expire' => DateTime::addSeconds(new \DateTime(), 36000)
        ]);

        /**
         * Test for SUCCESS
         */
        for ($i = 0; $i < 10; $i++) {
            $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
            ], [
                'email' => 'user@appwrite.io',
                'password' => 'password'
            ]);
            $this->assertEquals(401, $response['headers']['status-code']);
        }
        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], [
            'email' => 'user@appwrite.io',
            'password' => 'password'
        ]);
        $this->assertEquals(429, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-dev-key' => $devKey['secret']
        ], [
            'email' => 'user@appwrite.io',
            'password' => 'password'
        ]);
        $this->assertEquals(401, $response['headers']['status-code']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $projectId . '/dev-keys', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Key Test',
            'expire' => DateTime::addSeconds(new \DateTime(), -3600),
        ]);

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-dev-key' => $response['body']['secret']
        ], [
            'email' => 'user@appwrite.io',
            'password' => 'password'
        ]);
        $this->assertEquals(429, $response['headers']['status-code']);

        /**
         * Test for FAILURE after expire
         */
        $devKey = $this->setupDevKey([
            'projectId' => $projectId,
            'name' => 'Dev Key Test Expire 5 seconds',
            'expire' => DateTime::addSeconds(new \DateTime(), 5)
        ]);

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-dev-key' => $devKey['secret']
        ], [
            'email' => 'user@appwrite.io',
            'password' => 'password'
        ]);
        $this->assertEquals(401, $response['headers']['status-code']);

        sleep(5);

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-dev-key' => $devKey['secret']
        ], [
            'email' => 'user@appwrite.io',
            'password' => 'password'
        ]);
        $this->assertEquals(429, $response['headers']['status-code']);
    }

    /**
     * @group devKeys
     */
    public function testUpdateProjectDevKey(): void
    {
        $projectId = $this->setupProject([
            'projectId' => ID::unique(),
            'name' => 'testUpdateProjectDevKey',
            'region' => System::getEnv('_APP_REGION', 'default')
        ]);

        $devKey = $this->setupDevKey([
            'projectId' => $projectId,
            'name' => 'Dev Key Test',
            'expire' => DateTime::addSeconds(new \DateTime(), 36000)
        ]);

        $response = $this->client->call(Client::METHOD_PUT, '/projects/' . $projectId . '/dev-keys/' . $devKey['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Key Test Update',
            'expire' => DateTime::addSeconds(new \DateTime(), 360),
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals($devKey['$id'], $response['body']['$id']);
        $this->assertEquals('Key Test Update', $response['body']['name']);
        $this->assertArrayHasKey('accessedAt', $response['body']);
        $this->assertEmpty($response['body']['accessedAt']);

        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $projectId . '/dev-keys/' . $devKey['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals($devKey['$id'], $response['body']['$id']);
        $this->assertEquals('Key Test Update', $response['body']['name']);
        $this->assertArrayHasKey('accessedAt', $response['body']);
        $this->assertEmpty($response['body']['accessedAt']);
    }

    /**
     * @group devKeys
     */
    public function testDeleteProjectDevKey(): void
    {
        $projectId = $this->setupProject([
            'projectId' => ID::unique(),
            'name' => 'testDeleteProjectDevKey',
            'region' => System::getEnv('_APP_REGION', 'default')
        ]);

        $devKey = $this->setupDevKey([
            'projectId' => $projectId,
            'name' => 'Dev Key Test',
            'expire' => DateTime::addSeconds(new \DateTime(), 36000)
        ]);

        $response = $this->client->call(Client::METHOD_DELETE, '/projects/' . $projectId . '/dev-keys/' . $devKey['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(204, $response['headers']['status-code']);
        $this->assertEmpty($response['body']);

        /**
        * Get rate limit trying to use the deleted key
        */
        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-dev-key' => $devKey['secret']
        ], [
            'email' => 'user@appwrite.io',
            'password' => 'password'
        ]);
        $this->assertEquals(429, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $projectId . '/dev-keys/' . $devKey['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(404, $response['headers']['status-code']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_DELETE, '/projects/' . $projectId . '/dev-keys/error', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(404, $response['headers']['status-code']);
    }

    /**
     * Devkeys Tests ends here ------------------------------------------------
     */
}
