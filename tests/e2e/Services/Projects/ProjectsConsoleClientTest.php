<?php

namespace Tests\E2E\Services\Projects;

use Appwrite\Extend\Exception;
use Appwrite\Tests\Async;
use PHPUnit\Framework\Attributes\Group;
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

    #[Group('smtpAndTemplates')]
    #[Group('projectsCRUD')]
    public function testCreateProject(): void
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
        $this->assertEquals(PROJECT_STATUS_ACTIVE, $response['body']['status']);
        $this->assertArrayHasKey('platforms', $response['body']);
        $this->assertArrayHasKey('webhooks', $response['body']);
        $this->assertArrayHasKey('keys', $response['body']);

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
        $this->assertEquals(PROJECT_STATUS_ACTIVE, $response['body']['status']);
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

    #[Group('projectsCRUD')]
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

    #[Group('projectsCRUD')]
    public function testListProject(): void
    {
        $data = $this->setupProjectData();
        $id = $data['projectId'];

        /**
         * Test for SUCCESS
         */

        $response = $this->client->call(Client::METHOD_GET, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertGreaterThan(0, count($response['body']['projects']));

        /**
         * Test search queries
         */
        $response = $this->client->call(Client::METHOD_GET, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders(), [
            'search' => $id
        ]));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertGreaterThan(0, $response['body']['total']);
        $this->assertIsArray($response['body']['projects']);

        $response = $this->client->call(Client::METHOD_GET, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders(), [
            'search' => 'Project Test'
        ]));

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertGreaterThan(0, $response['body']['total']);
        $this->assertIsArray($response['body']['projects']);

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

        $response = $this->client->call(Client::METHOD_GET, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::offset(1)->toString(),
            ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);

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
        $this->assertGreaterThanOrEqual(1, count($response['body']['projects']));
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
        $this->assertGreaterThan(0, count($response['body']['projects']));

        $response = $this->client->call(Client::METHOD_GET, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertGreaterThan(0, count($response['body']['projects']));

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
    }

    #[Group('projectsCRUD')]
    public function testListProjectsQuerySelect(): void
    {
        $team = $this->client->call(Client::METHOD_POST, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'teamId' => ID::unique(),
            'name' => 'Query Select Test Team',
        ]);

        $this->assertEquals(201, $team['headers']['status-code']);
        $teamId = $team['body']['$id'];

        $project = $this->client->call(Client::METHOD_POST, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'projectId' => ID::unique(),
            'name' => 'Query Select Test Project',
            'teamId' => $teamId,
            'region' => System::getEnv('_APP_REGION', 'default')
        ]);

        $this->assertEquals(201, $project['headers']['status-code']);
        $projectId = $project['body']['$id'];

        /**
         * Test Query.select - basic fields
         */
        $response = $this->client->call(Client::METHOD_GET, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::select(['$id', 'name'])->toString(),
            ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertGreaterThan(0, count($response['body']['projects']));

        $project = $response['body']['projects'][0];
        $this->assertArrayHasKey('$id', $project);
        $this->assertArrayHasKey('name', $project);
        $this->assertArrayNotHasKey('platforms', $project);
        $this->assertArrayNotHasKey('webhooks', $project);
        $this->assertArrayNotHasKey('keys', $project);
        $this->assertArrayNotHasKey('devKeys', $project);
        $this->assertArrayNotHasKey('oAuthProviders', $project);
        $this->assertArrayNotHasKey('smtpEnabled', $project);
        $this->assertArrayNotHasKey('smtpHost', $project);
        $this->assertArrayNotHasKey('authLimit', $project);
        $this->assertArrayNotHasKey('authDuration', $project);

        /**
         * Test Query.select - multiple fields
         */
        $response = $this->client->call(Client::METHOD_GET, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::select(['$id', 'name', 'teamId', 'description', '$createdAt', '$updatedAt'])->toString(),
            ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertGreaterThan(0, count($response['body']['projects']));

        $project = $response['body']['projects'][0];
        $this->assertArrayHasKey('$id', $project);
        $this->assertArrayHasKey('name', $project);
        $this->assertArrayHasKey('teamId', $project);
        $this->assertArrayHasKey('description', $project);
        $this->assertArrayHasKey('$createdAt', $project);
        $this->assertArrayHasKey('$updatedAt', $project);
        $this->assertArrayNotHasKey('platforms', $project);
        $this->assertArrayNotHasKey('webhooks', $project);
        $this->assertArrayNotHasKey('keys', $project);
        $this->assertArrayNotHasKey('devKeys', $project);
        $this->assertArrayNotHasKey('oAuthProviders', $project);
        $this->assertArrayNotHasKey('smtpEnabled', $project);
        $this->assertArrayNotHasKey('authLimit', $project);

        /**
         * Test Query.select combined with filters
         */
        $response = $this->client->call(Client::METHOD_GET, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::select(['$id', 'name', 'teamId'])->toString(),
                Query::equal('name', ['Query Select Test Project'])->toString(),
            ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertCount(1, $response['body']['projects']);

        $project = $response['body']['projects'][0];
        $this->assertArrayHasKey('$id', $project);
        $this->assertArrayHasKey('name', $project);
        $this->assertArrayHasKey('teamId', $project);
        $this->assertEquals('Query Select Test Project', $project['name']);
        $this->assertEquals($teamId, $project['teamId']);
        $this->assertArrayNotHasKey('platforms', $project);
        $this->assertArrayNotHasKey('webhooks', $project);
        $this->assertArrayNotHasKey('keys', $project);
        $this->assertArrayNotHasKey('devKeys', $project);
        $this->assertArrayNotHasKey('oAuthProviders', $project);
        $this->assertArrayNotHasKey('smtpEnabled', $project);
        $this->assertArrayNotHasKey('authLimit', $project);

        /**
         * Test Query.select combined with limit
         */
        $response = $this->client->call(Client::METHOD_GET, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::select(['$id', 'name'])->toString(),
                Query::limit(2)->toString(),
            ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertLessThanOrEqual(2, count($response['body']['projects']));

        foreach ($response['body']['projects'] as $p) {
            $this->assertArrayHasKey('$id', $p);
            $this->assertArrayHasKey('name', $p);
            $this->assertArrayNotHasKey('platforms', $p);
            $this->assertArrayNotHasKey('webhooks', $p);
            $this->assertArrayNotHasKey('keys', $p);
            $this->assertArrayNotHasKey('devKeys', $p);
            $this->assertArrayNotHasKey('oAuthProviders', $p);
            $this->assertArrayNotHasKey('smtpEnabled', $p);
            $this->assertArrayNotHasKey('authLimit', $p);
        }

        /**
         * Test Query.select with subquery attributes (platforms, webhooks, etc.)
         * When explicitly selected, subqueries should still run
         */
        $response = $this->client->call(Client::METHOD_GET, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::select(['$id', 'name', 'platforms'])->toString(),
            ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertGreaterThan(0, count($response['body']['projects']));

        $project = $response['body']['projects'][0];
        $this->assertArrayHasKey('$id', $project);
        $this->assertArrayHasKey('name', $project);
        $this->assertArrayHasKey('platforms', $project);
        $this->assertIsArray($project['platforms']);
        $this->assertArrayNotHasKey('webhooks', $project);
        $this->assertArrayNotHasKey('keys', $project);
        $this->assertArrayNotHasKey('devKeys', $project);
        $this->assertArrayNotHasKey('oAuthProviders', $project);
        $this->assertArrayNotHasKey('smtpEnabled', $project);
        $this->assertArrayNotHasKey('authLimit', $project);

        /**
         * Test Query.select with expanded attributes
         * webhooks and keys should load their subquery data when selected
         */
        $response = $this->client->call(Client::METHOD_GET, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::select(['$id', 'name', 'webhooks', 'keys'])->toString(),
            ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertGreaterThan(0, count($response['body']['projects']));

        $project = $response['body']['projects'][0];
        $this->assertArrayHasKey('$id', $project);
        $this->assertArrayHasKey('name', $project);
        $this->assertArrayHasKey('webhooks', $project);
        $this->assertArrayHasKey('keys', $project);
        $this->assertIsArray($project['webhooks']);
        $this->assertIsArray($project['keys']);
        $this->assertArrayNotHasKey('platforms', $project);
        $this->assertArrayNotHasKey('devKeys', $project);
        $this->assertArrayNotHasKey('smtpEnabled', $project);
        $this->assertArrayNotHasKey('authLimit', $project);

        /**
         * Test Query.select with wildcard '*'
         * Should return all fields like no select query
         */
        $response = $this->client->call(Client::METHOD_GET, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::select(['*'])->toString(),
            ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertGreaterThan(0, count($response['body']['projects']));

        $project = $response['body']['projects'][0];
        $this->assertArrayHasKey('$id', $project);
        $this->assertArrayHasKey('name', $project);
        $this->assertArrayHasKey('teamId', $project);
        $this->assertArrayHasKey('platforms', $project);
        $this->assertArrayHasKey('webhooks', $project);
        $this->assertArrayHasKey('keys', $project);
        $this->assertArrayHasKey('devKeys', $project);
        $this->assertArrayHasKey('oAuthProviders', $project);
        $this->assertArrayHasKey('smtpEnabled', $project);
        $this->assertArrayHasKey('smtpHost', $project);
        $this->assertArrayHasKey('authLimit', $project);
        $this->assertArrayHasKey('authDuration', $project);

        /**
         * Test Query.select with invalid attribute
         */
        $response = $this->client->call(Client::METHOD_GET, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::select(['$id', 'invalidAttribute'])->toString(),
            ],
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
        $this->assertEquals('Invalid `queries` param: Invalid query: Attribute not found in schema: invalidAttribute', $response['body']['message']);

        $response = $this->client->call(Client::METHOD_DELETE, '/projects/' . $projectId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(204, $response['headers']['status-code']);
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
        $projectId = str_repeat('very_long_id', 25); // 12 chars * 25 = 300 chars > MongoDB max (255)

        $response = $this->client->call(Client::METHOD_GET, '/projects/'.$projectId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(400, $response['headers']['status-code']);
    }

    public function testGetProjectUsage(): void
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

    #[Group('smtpAndTemplates')]
    public function testUpdateProjectSMTP(): void
    {
        $data = $this->setupProjectData();
        $id = $data['projectId'];
        $smtpHost = System::getEnv('_APP_SMTP_HOST', "maildev");
        $smtpPort = intval(System::getEnv('_APP_SMTP_PORT', "1025"));
        $smtpUsername = System::getEnv('_APP_SMTP_USERNAME', 'user');
        $smtpPassword = System::getEnv('_APP_SMTP_PASSWORD', 'password');

        /**
         * Test for SUCCESS: Valid Credentials
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $id . '/smtp', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'enabled' => true,
            'senderEmail' => 'mailer@appwrite.io',
            'senderName' => 'Mailer',
            'host' => $smtpHost,
            'port' => $smtpPort,
            'username' => $smtpUsername,
            'password' => $smtpPassword,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertTrue($response['body']['smtpEnabled']);
        $this->assertEquals('mailer@appwrite.io', $response['body']['smtpSenderEmail']);
        $this->assertEquals('Mailer', $response['body']['smtpSenderName']);
        $this->assertEquals($smtpHost, $response['body']['smtpHost']);
        $this->assertEquals($smtpPort, $response['body']['smtpPort']);
        $this->assertEquals($smtpUsername, $response['body']['smtpUsername']);
        $this->assertEquals($smtpPassword, $response['body']['smtpPassword']);
        $this->assertEquals('', $response['body']['smtpSecure']);

        // Check the project
        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertTrue($response['body']['smtpEnabled']);
        $this->assertEquals('mailer@appwrite.io', $response['body']['smtpSenderEmail']);
        $this->assertEquals('Mailer', $response['body']['smtpSenderName']);
        $this->assertEquals($smtpHost, $response['body']['smtpHost']);
        $this->assertEquals($smtpPort, $response['body']['smtpPort']);
        $this->assertEquals($smtpUsername, $response['body']['smtpUsername']);
        $this->assertEquals($smtpPassword, $response['body']['smtpPassword']);
        $this->assertEquals('', $response['body']['smtpSecure']);

        /**
         * Test for FAILURE: Invalid Credentials
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $id . '/smtp', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'enabled' => true,
            'senderEmail' => 'fail@appwrite.io',
            'senderName' => 'Failing Mailer',
            'host' => $smtpHost,
            'port' => $smtpPort,
            'username' => 'invalid-user',
            'password' => 'bad-password',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
        $this->assertEquals(Exception::PROJECT_SMTP_CONFIG_INVALID, $response['body']['type']);
        $this->assertStringContainsStringIgnoringCase('Could not authenticate', $response['body']['message']);
    }

    #[Group('smtpAndTemplates')]
    public function testCreateProjectSMTPTests(): void
    {
        $smtpHost = System::getEnv('_APP_SMTP_HOST', "maildev");
        $smtpPort = intval(System::getEnv('_APP_SMTP_PORT', "1025"));
        $smtpUsername = System::getEnv('_APP_SMTP_USERNAME', 'user');
        $smtpPassword = System::getEnv('_APP_SMTP_PASSWORD', 'password');

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
            'host' => $smtpHost,
            'port' => $smtpPort,
            'username' => $smtpUsername,
            'password' => $smtpPassword,
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
            'host' => $smtpHost,
            'port' => $smtpPort,
            'username' => $smtpUsername,
            'password' => $smtpPassword,
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
            'host' => $smtpHost,
            'port' => $smtpPort,
            'username' => $smtpUsername,
            'password' => $smtpPassword,
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
    }

    #[Group('smtpAndTemplates')]
    public function testUpdateTemplates(): void
    {
        $data = $this->setupProjectData();
        $id = $data['projectId'];

        /** Get Default Email Template */
        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id . '/templates/email/verification/en-us', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('Account Verification for {{project}}', $response['body']['subject']);
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
    }

    public function testUpdateProjectAuthDuration(): void
    {
        $data = $this->setupProjectData();
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
            'duration' => 10, // Set session duration to 10 seconds
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals('Project Test', $response['body']['name']);
        $this->assertArrayHasKey('platforms', $response['body']);
        $this->assertArrayHasKey('webhooks', $response['body']);
        $this->assertArrayHasKey('keys', $response['body']);
        $this->assertEquals(10, $response['body']['authDuration']);

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

        // Eventually session expires, within 15 seconds (10+variance)
        $this->assertEventually(function () use ($projectId, $sessionCookie) {
            // Get User
            $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
                'Cookie' => $sessionCookie,
            ]));

            $this->assertEquals(401, $response['headers']['status-code']);
        }, timeoutMs: 15 * 1000);

        // Set session duration to 10min
        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $id . '/auth/duration', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'duration' => 600, // seconds
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(600, $response['body']['authDuration']);

        // Ensure session is still expired (new duration only affects new sessions)
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

    public function testUpdateProjectAuthLimit(): void
    {
        $data = $this->setupProjectData();
        $id = $data['projectId'];

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
    }

    public function testUpdateProjectAuthSessionsLimit(): void
    {
        $id = $this->setupProject([
            'projectId' => ID::unique(),
            'name' => 'testUpdateProjectAuthSessionsLimit',
            'region' => System::getEnv('_APP_REGION', 'default')
        ]);

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

        sleep(5); // fixes flaky tests.

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
        }, 120_000, 300);

        /**
         * Reset Limit
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $id . '/auth/max-sessions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'limit' => 10,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
    }

    public function testUpdateProjectAuthPasswordHistory(): void
    {
        $data = $this->setupProjectWithAuthLimit();
        $id = $data['projectId'];

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
    }

    #[Group('smtpAndTemplates')]
    #[Group('projectsCRUD')]
    public function testUpdateMockNumbers(): void
    {
        $data = $this->setupProjectData();
        $id = $data['projectId'];

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
        $this->assertStringContainsString('Value must a valid array no longer than 10 items', $response['body']['message']);

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

    public function testUpdateProjectAuthPasswordDictionary(): void
    {
        $data = $this->setupProjectWithAuthLimit();
        $id = $data['projectId'];

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
    }

    public function testUpdateDisallowPersonalData(): void
    {
        $data = $this->setupProjectData();
        $id = $data['projectId'];

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

    public function testUpdateProjectServiceStatus(): void
    {
        $data = $this->setupProjectWithServicesDisabled();
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

    public function testUpdateProjectServiceStatusServer(): void
    {
        $data = $this->setupProjectWithServicesDisabled();
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
            'keyId' => ID::unique(),
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

    public function testCreateProjectWebhook(): void
    {
        $data = $this->setupProjectData();
        $id = $data['projectId'];

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
    }

    public function testListProjectWebhook(): void
    {
        $data = $this->setupProjectWithWebhook();
        $id = $data['projectId'];

        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id . '/webhooks', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $response['headers']['status-code']);
        // In parallel mode, multiple tests may create webhooks on the same project
        $this->assertGreaterThanOrEqual(1, $response['body']['total']);

        /**
         * Test for FAILURE
         */
    }

    public function testGetProjectWebhook(): void
    {
        $data = $this->setupProjectWithWebhook();
        $id = $data['projectId'];
        $webhookId = $data['webhookId'];

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
    }

    public function testUpdateProjectWebhook(): void
    {
        $data = $this->setupProjectWithWebhook();
        $id = $data['projectId'];
        $webhookId = $data['webhookId'];

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
    }

    public function testUpdateProjectWebhookSignature(): void
    {
        $data = $this->setupProjectWithWebhook();
        $id = $data['projectId'];
        $webhookId = $data['webhookId'];
        $signatureKey = $data['signatureKey'];

        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $id . '/webhooks/' . $webhookId . '/signature', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['signatureKey']);
        $this->assertNotEquals($signatureKey, $response['body']['signatureKey']);
    }

    public function testDeleteProjectWebhook(): void
    {
        // Create a fresh project with webhook for deletion test
        $projectData = $this->setupProjectData();
        $id = $projectData['projectId'];

        // Create a webhook to delete
        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $id . '/webhooks', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Webhook To Delete',
            'events' => ['users.*.create'],
            'url' => 'https://appwrite.io',
            'security' => true,
            'httpUser' => 'username',
            'httpPass' => 'password',
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $webhookId = $response['body']['$id'];

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
    }

    // Keys

    public function testCreateProjectKey(): void
    {
        $data = $this->setupProjectData();
        $id = $data['projectId'];

        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $id . '/keys', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'keyId' => ID::unique(),
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

        /**
         * Test for SUCCESS without key ID
         */
        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $id . '/keys', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Key Custom',
            'scopes' => ['teams.read', 'teams.write'],
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);

        /**
         * Test for SUCCESS with custom ID
         */
        $customKeyId = \uniqid() . 'custom-id';
        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $id . '/keys', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'keyId' => $customKeyId,
            'name' => 'Key Custom',
            'scopes' => ['teams.read', 'teams.write'],
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertSame($customKeyId, $response['body']['$id']);

        /**
         * Test for FAILURE with custom ID
         */
        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $id . '/keys', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'keyId' => $customKeyId,
            'name' => 'Key Custom',
            'scopes' => ['teams.read', 'teams.write'],
        ]);

        $this->assertEquals(409, $response['headers']['status-code']);

        /**
         * Test for SUCCESS with magic string ID
         */
        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $id . '/keys', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'keyId' => 'unique()',
            'name' => 'Key Custom',
            'scopes' => ['teams.read', 'teams.write'],
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertNotSame('unique()', $response['body']['$id']);

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
            'keyId' => ID::unique(),
            'name' => 'Key Test',
            'scopes' => ['unknown'],
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
    }


    public function testListProjectKey(): void
    {
        $data = $this->setupProjectWithKey();
        $id = $data['projectId'];

        /** Create a second key with an expiry for query testing */
        $expireDate = DateTime::addSeconds(new \DateTime(), 3600);
        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $id . '/keys', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Key Test 2',
            'scopes' => ['users.read'],
            'expire' => $expireDate,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $key2Id = $response['body']['$id'];

        /** List all keys (no queries) — count depends on how many test methods ran before this in the same worker */
        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id . '/keys', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $totalKeys = $response['body']['total'];
        $this->assertGreaterThanOrEqual(2, $totalKeys);
        $this->assertCount($totalKeys, $response['body']['keys']);

        /** List keys with limit */
        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id . '/keys', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::limit(1)->toString(),
            ]
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(1, $response['body']['keys']);
        $this->assertEquals($totalKeys, $response['body']['total']);

        /** List keys with offset */
        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id . '/keys', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::offset(1)->toString(),
            ]
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount($totalKeys - 1, $response['body']['keys']);
        $this->assertEquals($totalKeys, $response['body']['total']);

        /** List keys with cursor after */
        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id . '/keys', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::cursorAfter(new Document(['$id' => $data['keyId']]))->toString(),
            ]
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertGreaterThanOrEqual(1, $response['body']['keys']);
        $this->assertEquals($totalKeys, $response['body']['total']);

        /** List keys filtering by expire (lessThan now — should match none) */
        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id . '/keys', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::lessThan('expire', (new \DateTime())->format('Y-m-d H:i:s'))->toString(),
            ]
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(0, $response['body']['total']);

        /** List keys filtering by expire (greaterThan now — should match the key with expiry) */
        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id . '/keys', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::greaterThan('expire', (new \DateTime())->format('Y-m-d H:i:s'))->toString(),
            ]
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        // In parallel mode, multiple tests may create keys on the same project
        $this->assertGreaterThanOrEqual(1, $response['body']['total']);

        /**
         * Test for FAILURE
         */

        /** Test invalid query attribute */
        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id . '/keys', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::equal('secret', ['test'])->toString(),
            ]
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        /** Test invalid cursor */
        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id . '/keys', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::cursorAfter(new Document(['$id' => 'invalid']))->toString(),
            ]
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
    }


    public function testGetProjectKey(): void
    {
        $data = $this->setupProjectWithKey();
        $id = $data['projectId'];
        $keyId = $data['keyId'];

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
    }

    public function testValidateProjectKey(): void
    {
        $data = $this->setupProjectData();
        $projectId = $data['projectId'];
        $teamId = $data['teamId'];

        /**
         * Test for SUCCESS
         */

        // Expiring key
        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $projectId . '/keys', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'keyId' => ID::unique(),
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
            'keyId' => ID::unique(),
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
            'keyId' => ID::unique(),
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
            'keyId' => ID::unique(),
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
            'keyId' => ID::unique(),
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


    public function testUpdateProjectKey(): void
    {
        $data = $this->setupProjectWithKey();
        $id = $data['projectId'];
        $keyId = $data['keyId'];

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
        $this->assertCount(4, $response['body']['scopes']);
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
        $this->assertCount(4, $response['body']['scopes']);
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
    }

    public function testDeleteProjectKey(): void
    {
        // Create a fresh key for deletion testing (cannot use cached key)
        $projectData = $this->setupProjectData();
        $id = $projectData['projectId'];

        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $id . '/keys', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Key For Deletion',
            'scopes' => ['teams.read', 'teams.write'],
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $keyId = $response['body']['$id'];

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
    }

    public function testCreateProjectKeyOutdated(): void
    {
        $data = $this->setupProjectData();
        $id = $data['projectId'];

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

    public function testJWTKey(): void
    {
        $data = $this->setupProjectData();
        $id = $data['projectId'];

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

    public function testCreateProjectPlatform(): void
    {
        $data = $this->setupProjectData();
        $id = $data['projectId'];

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
    }

    public function testListProjectPlatform(): void
    {
        $data = $this->setupProjectWithPlatform();
        $id = $data['projectId'];

        $this->assertEventually(function () use ($id) {
            $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id . '/platforms', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()), []);

            $this->assertEquals(200, $response['headers']['status-code']);
            // In parallel mode, multiple tests may create platforms on the same project
            // The setup creates 8 platforms, so we should have at least that many
            $this->assertGreaterThanOrEqual(8, $response['body']['total']);
        });

        /**
         * Test for FAILURE
         */
    }

    public function testGetProjectPlatform(): void
    {
        $data = $this->setupProjectWithPlatform();
        $id = $data['projectId'];

        $platformWebId = $data['platformWebId'];

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

        $platformFultteriOSId = $data['platformFultteriOSId'];

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

        $platformFultterAndroidId = $data['platformFultterAndroidId'];

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

        $platformFultterWebId = $data['platformFultterWebId'];

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

        $platformAppleIosId = $data['platformAppleIosId'];

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

        $platformAppleMacOsId = $data['platformAppleMacOsId'];

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

        $platformAppleWatchOsId = $data['platformAppleWatchOsId'];

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

        $platformAppleTvOsId = $data['platformAppleTvOsId'];

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
    }

    public function testUpdateProjectPlatform(): void
    {
        $data = $this->setupProjectWithPlatform();
        $id = $data['projectId'];

        $platformWebId = $data['platformWebId'];

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

        $platformFultteriOSId = $data['platformFultteriOSId'];

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

        $platformFultterAndroidId = $data['platformFultterAndroidId'];

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

        $platformFultterWebId = $data['platformFultterWebId'];

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

        $platformAppleIosId = $data['platformAppleIosId'];

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

        $platformAppleMacOsId = $data['platformAppleMacOsId'];

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

        $platformAppleWatchOsId = $data['platformAppleWatchOsId'];

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

        $platformAppleTvOsId = $data['platformAppleTvOsId'];

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
    }

    public function testDeleteProjectPlatform(): void
    {
        // Create a fresh project with platforms for deletion testing (cannot use cached platforms)
        $projectData = $this->setupProjectData();
        $id = $projectData['projectId'];

        // Create web platform
        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $id . '/platforms', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'type' => 'web',
            'name' => 'Web App',
            'hostname' => 'localhost',
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);
        $platformWebId = $response['body']['$id'];

        // Create flutter-ios platform
        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $id . '/platforms', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'type' => 'flutter-ios',
            'name' => 'Flutter App (iOS)',
            'key' => 'com.example.ios',
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);
        $platformFultteriOSId = $response['body']['$id'];

        // Create flutter-android platform
        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $id . '/platforms', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'type' => 'flutter-android',
            'name' => 'Flutter App (Android)',
            'key' => 'com.example.android',
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);
        $platformFultterAndroidId = $response['body']['$id'];

        // Create flutter-web platform
        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $id . '/platforms', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'type' => 'flutter-web',
            'name' => 'Flutter App (Web)',
            'hostname' => 'flutter.appwrite.io',
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);
        $platformFultterWebId = $response['body']['$id'];

        // Create apple-ios platform
        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $id . '/platforms', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'type' => 'apple-ios',
            'name' => 'iOS App',
            'key' => 'com.example.ios',
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);
        $platformAppleIosId = $response['body']['$id'];

        // Create apple-macos platform
        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $id . '/platforms', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'type' => 'apple-macos',
            'name' => 'macOS App',
            'key' => 'com.example.macos',
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);
        $platformAppleMacOsId = $response['body']['$id'];

        // Create apple-watchos platform
        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $id . '/platforms', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'type' => 'apple-watchos',
            'name' => 'watchOS App',
            'key' => 'com.example.watchos',
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);
        $platformAppleWatchOsId = $response['body']['$id'];

        // Create apple-tvos platform
        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $id . '/platforms', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'type' => 'apple-tvos',
            'name' => 'tvOS App',
            'key' => 'com.example.tvos',
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);
        $platformAppleTvOsId = $response['body']['$id'];

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
            'keyId' => ID::unique(),
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
            'keyId' => ID::unique(),
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

    public function testCreateProjectVariable(): void
    {
        $data = $this->setupProjectData();

        /**
         * Test for SUCCESS
         */
        $variable = $this->client->call(Client::METHOD_POST, '/project/variables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $data['projectId'],
            'x-appwrite-mode' => 'admin',
        ], $this->getHeaders()), [
            'key' => 'APP_TEST_CREATE',
            'value' => 'TESTINGVALUE',
            'secret' => false
        ]);

        $this->assertEquals(201, $variable['headers']['status-code']);
        $this->assertEquals('APP_TEST_CREATE', $variable['body']['key']);
        $this->assertEquals('TESTINGVALUE', $variable['body']['value']);
        $this->assertFalse($variable['body']['secret']);

        // test for secret variable
        $variable = $this->client->call(Client::METHOD_POST, '/project/variables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $data['projectId'],
            'x-appwrite-mode' => 'admin',
        ], $this->getHeaders()), [
            'key' => 'APP_TEST_CREATE_1',
            'value' => 'TESTINGVALUE_1',
            'secret' => true
        ]);

        $this->assertEquals(201, $variable['headers']['status-code']);
        $this->assertEquals('APP_TEST_CREATE_1', $variable['body']['key']);
        $this->assertEmpty($variable['body']['value']);

        /**
         * Test for FAILURE
         */
        // Test for duplicate key
        $variable = $this->client->call(Client::METHOD_POST, '/project/variables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $data['projectId'],
            'x-appwrite-mode' => 'admin',
        ], $this->getHeaders()), [
            'key' => 'APP_TEST_CREATE',
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
    }

    public function testListVariables(): void
    {
        $data = $this->setupProjectWithVariable();

        /**
         * Test for SUCCESS
         */

        $response = $this->client->call(Client::METHOD_GET, '/project/variables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $data['projectId'],
            'x-appwrite-mode' => 'admin',
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertGreaterThanOrEqual(2, count($response['body']['variables']));
        $this->assertGreaterThanOrEqual(2, $response['body']['total']);
    }

    public function testGetVariable(): void
    {
        $data = $this->setupProjectWithVariable();

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
    }

    public function testUpdateVariable(): void
    {
        $data = $this->setupProjectWithVariable();
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
        // In parallel mode, other tests may create variables on the same project
        $this->assertGreaterThanOrEqual(2, count($response['body']['variables']));
        // Verify our updated variables exist (may not be at specific positions)
        $variableKeys = array_column($response['body']['variables'], 'key');
        $this->assertContains("APP_TEST_UPDATE", $variableKeys);
        $this->assertContains("APP_TEST_UPDATE_1", $variableKeys);

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
    }

    public function testDeleteVariable(): void
    {
        // Create a fresh project with variables for deletion testing
        $projectData = $this->setupProjectData();

        // Create a non-secret variable
        $variable = $this->client->call(Client::METHOD_POST, '/project/variables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectData['projectId'],
            'x-appwrite-mode' => 'admin',
        ], $this->getHeaders()), [
            'key' => 'APP_TEST_DELETE',
            'value' => 'TESTINGVALUE',
            'secret' => false
        ]);

        $this->assertEquals(201, $variable['headers']['status-code']);
        $variableId = $variable['body']['$id'];

        // Create a secret variable
        $variable = $this->client->call(Client::METHOD_POST, '/project/variables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectData['projectId'],
            'x-appwrite-mode' => 'admin',
        ], $this->getHeaders()), [
            'key' => 'APP_TEST_DELETE_1',
            'value' => 'TESTINGVALUE_1',
            'secret' => true
        ]);

        $this->assertEquals(201, $variable['headers']['status-code']);
        $secretVariableId = $variable['body']['$id'];

        /**
         * Test for SUCCESS
         */

        $response = $this->client->call(Client::METHOD_DELETE, '/project/variables/' . $variableId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectData['projectId'],
            'x-appwrite-mode' => 'admin',
        ], $this->getHeaders()));

        $this->assertEquals(204, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_DELETE, '/project/variables/' . $secretVariableId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectData['projectId'],
            'x-appwrite-mode' => 'admin',
        ], $this->getHeaders()));

        $this->assertEquals(204, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/project/variables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectData['projectId'],
            'x-appwrite-mode' => 'admin',
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        // In parallel mode, other tests may have created variables on the same project
        // Verify our deleted variables no longer exist by checking their IDs are not present
        $variableIds = array_column($response['body']['variables'], '$id');
        $this->assertNotContains($variableId, $variableIds);
        $this->assertNotContains($secretVariableId, $variableIds);

        /**
         * Test for FAILURE
         */

        $response = $this->client->call(Client::METHOD_DELETE, '/project/variables/NON_EXISTING_VARIABLE', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectData['projectId'],
            'x-appwrite-mode' => 'admin',
        ], $this->getHeaders()));

        $this->assertEquals(404, $response['headers']['status-code']);
    }

    /**
     * Devkeys Tests starts here ------------------------------------------------
     */

    #[Group('abuseEnabled')]
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


    #[Group('abuseEnabled')]
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


    #[Group('abuseEnabled')]
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

    #[Group('abuseEnabled')]
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

    #[Group('abuseEnabled')]
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

        /** Test oauth2 with devKey and now flow works with untrusted URL too */
        $response = $this->client->call(Client::METHOD_GET, '/account/sessions/oauth2/' . $provider, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-dev-key' => $devKey['secret']
        ], [
            'success' => 'https://example.com',
            'failure' => 'https://example.com'
        ], followRedirects: false);

        $this->assertEquals(301, $response['headers']['status-code']);
        $this->assertArrayHasKey('location', $response['headers']);

        $location = $response['headers']['location'];


        $locationClient = new Client();
        $locationClient->setEndpoint('');
        $locationClient->addHeader('x-appwrite-dev-key', $devKey['secret']);

        $response = $locationClient->call(Client::METHOD_GET, $location, followRedirects: false);

        $this->assertEquals(301, $response['headers']['status-code']);
        $this->assertArrayHasKey('location', $response['headers']);

        $location = $response['headers']['location'];
        $this->assertStringStartsWith('http://appwrite:/v1/account/sessions/oauth2/callback/mock/', $response['headers']['location']);

        $response = $locationClient->call(Client::METHOD_GET, $location, followRedirects: false);
        $this->assertEquals(301, $response['headers']['status-code']);
        $this->assertArrayHasKey('location', $response['headers']);

        $location = $response['headers']['location'];
        $this->assertStringStartsWith('http://appwrite:/v1/account/sessions/oauth2/mock/redirect', $response['headers']['location']);

        $response = $locationClient->call(Client::METHOD_GET, $location, followRedirects: false);

        $this->assertEquals(301, $response['headers']['status-code']);
        $this->assertSame('https://example.com/#', $response['headers']['location']);

        /** Ensure any hostname is allowed */
        $response = $this->client->call(Client::METHOD_GET, '/account/sessions/oauth2/' . $provider, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-dev-key' => $devKey['secret'],
            'origin' => '',
            'referer' => 'https://domain-without-rule.com'
        ], [
            'success' => 'https://domain-without-rule.com',
            'failure' => 'https://domain-without-rule.com'
        ], followRedirects: false);
        $this->assertEquals(301, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/account/sessions/oauth2/' . $provider, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-dev-key' => $devKey['secret'],
            'referer' => '',
            'origin' => 'https://domain-without-rule.com'
        ], [
            'success' => 'https://domain-without-rule.com',
            'failure' => 'https://domain-without-rule.com'
        ], followRedirects: false);
        $this->assertEquals(301, $response['headers']['status-code']);

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

    public function testRuleOAuthRedirect(): void
    {
        // Prepare project
        $projectId = $this->setupProject([
            'projectId' => ID::unique(),
            'name' => 'testRuleOAuthRedirect',
            'region' => System::getEnv('_APP_REGION', 'default')
        ]);

        $provider = 'mock';
        $appId = '1';
        $secret = '123456';

        // Prepare OAuth provider
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

        // Prepare rule. In reality this is site rule, but for testing, API rule is enough, and faster to prepare
        $domain = \uniqid() . '-with-rule.custom.localhost';
        $rule = $this->client->call(Client::METHOD_POST, '/proxy/rules/api', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-mode' => 'admin',
        ], $this->getHeaders()), [
            'domain' => $domain
        ]);

        $this->assertEquals(201, $rule['headers']['status-code']);

        // Ensure unknown domain cannot be redirect URL
        $response = $this->client->call(Client::METHOD_GET, '/account/sessions/oauth2/' . $provider, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'referer' => 'https://' . $domain,
            'origin' => '',
        ], [
            'success' => 'https://domain-without-rule.com',
            'failure' => 'https://domain-without-rule.com'
        ], followRedirects: false);
        $this->assertEquals(400, $response['headers']['status-code']);

        // Also ensure final step blocks unknown redirect URL
        $response = $this->client->call(Client::METHOD_GET, '/account/sessions/oauth2/' . $provider . '/redirect', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'origin' => '',
            'referer' => 'https://mockserver.com',
        ], [
            'code' => 'any-code',
            'state' => \json_encode([
                'success' => 'https://domain-without-rule.com',
                'failure' => 'https://domain-without-rule.com'
            ]),
            'error' => '',
            'error_description' => '',
        ], followRedirects: false);
        $this->assertEquals(400, $response['headers']['status-code']);
        $this->assertStringContainsString('project_invalid_success_url', $response['body']);

        // Ensure rule's domain can be redirect URL
        $response = $this->client->call(Client::METHOD_GET, '/account/sessions/oauth2/' . $provider, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'referer' => 'https://' . $domain,
            'origin' => '',
        ], [
            'success' => 'https://' . $domain,
            'failure' => 'https://' . $domain
        ], followRedirects: false);
        $this->assertEquals(301, $response['headers']['status-code']);

        // Also ensure final step allows redirect URL
        $response = $this->client->call(Client::METHOD_GET, '/account/sessions/oauth2/' . $provider . '/redirect', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'origin' => '',
            'referer' => 'https://mockserver.com',
        ], [
            'code' => 'any-code',
            'state' => \json_encode([
                'success' => 'https://' . $domain,
                'failure' => 'https://' . $domain
            ]),
            'error' => '',
            'error_deescription' => '',
        ], followRedirects: false);
        $this->assertEquals(301, $response['headers']['status-code']);
        $this->assertStringContainsString('https://' . $domain, $response['headers']['location']);

        // Ensure unknown domain cannot be redirect URL
        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/magic-url', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'referer' => 'https://' . $domain,
            'origin' => '',
        ], [
            'userId' => ID::unique(),
            'email' => 'user@appwrite.io',
            'url' => 'https://domain-without-rule.com',
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        // Ensure rule's domain can be redirect URL
        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/magic-url', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'referer' => 'https://' . $domain,
            'origin' => '',
        ], [
            'userId' => ID::unique(),
            'email' => 'user@appwrite.io',
            'url' => 'https://' . $domain,
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);
    }

    public function testOAuthRedirectWithCustomSchemeState(): void
    {
        // Prepare project
        $projectId = $this->setupProject([
            'projectId' => ID::unique(),
            'name' => 'testOAuthRedirectWithCustomSchemeState',
            'region' => System::getEnv('_APP_REGION', 'default')
        ]);

        $provider = 'mock';
        $appId = '1';
        $secret = '123456';

        // Prepare OAuth provider
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

        $scheme = 'appwrite-callback-' . $projectId;
        $state = \json_encode([
            'success' => $scheme . ':///',
            'failure' => $scheme . ':///'
        ]);

        $response = $this->client->call(Client::METHOD_GET, '/account/sessions/oauth2/' . $provider . '/redirect', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'origin' => '',
            'referer' => '',
        ], [
            'code' => 'any-code',
            'state' => $state,
            'error' => 'access_denied',
            'error_description' => 'test',
        ], followRedirects: false);

        $this->assertEquals(301, $response['headers']['status-code']);
        $this->assertStringStartsWith($scheme . '://', $response['headers']['location']);
        $this->assertStringContainsString('error=', $response['headers']['location']);
    }

    #[Group('abuseEnabled')]
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

    public function testConsoleCorsWithTrustedProject(): void
    {
        $trustedProjectIds = ['trusted-project', 'another-trusted-project']; // Set in env variable

        $projectIds = \array_merge($trustedProjectIds, ['untrusted-project-id']);

        foreach ($projectIds as $projectId) {
            try {
                // Create project
                $this->setupProject([
                    'projectId' => $projectId,
                    'name' => 'Trusted project',
                    'region' => System::getEnv('_APP_REGION', 'default')
                ]);

                // Add domain to trusted project; API for simplicity, in real work this will be site
                $domain = \uniqid() . '.custom.localhost';
                $rule = $this->client->call(Client::METHOD_POST, '/proxy/rules/api', array_merge([
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $projectId,
                    'x-appwrite-mode' => 'admin',
                ], $this->getHeaders()), [
                    'domain' => $domain
                ]);

                $this->assertEquals(201, $rule['headers']['status-code']);

                // Talk to Console APIs from trusted project domain
                $currencies = $this->client->call(
                    Client::METHOD_GET,
                    '/locale/currencies',
                    array_merge(
                        $this->getHeaders(),
                        [
                            'content-type' => 'application/json',
                            'x-appwrite-project' => 'console',
                            'origin' => 'http://' . $domain
                        ]
                    )
                );

                if (\in_array($projectId, $trustedProjectIds)) {
                    // Trusted projects can
                    $this->assertEquals(200, $currencies['headers']['status-code']);
                    $this->assertSame('http://' . $domain, $currencies['headers']['access-control-allow-origin']);
                } else {
                    // Untrusted projects cannot
                    $this->assertEquals(403, $currencies['headers']['status-code']);
                    $this->assertArrayNotHasKey('access-control-allow-origin', $currencies['headers']);
                }
            } finally {
                // Cleanup
                $response = $this->client->call(Client::METHOD_DELETE, '/projects/' . $projectId, array_merge([
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                ], $this->getHeaders()), []);

                $this->assertEquals(204, $response['headers']['status-code']);
            }
        }
    }

    #[Group('abuseEnabled')]
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

    #[Group('abuseEnabled')]
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

    #[Group('abuseEnabled')]
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

    public function testProjectLabels(): void
    {
        // Setup: Prepare team
        $team = $this->client->call(Client::METHOD_POST, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'teamId' => ID::unique(),
            'name' => 'Query Select Test Team',
        ]);

        $this->assertEquals(201, $team['headers']['status-code']);
        $teamId = $team['body']['$id'];

        // Setup: Prepare project
        $project = $this->client->call(Client::METHOD_POST, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'projectId' => ID::unique(),
            'name' => 'Test project - Labels 1',
            'teamId' => $teamId,
            'region' => System::getEnv('_APP_REGION', 'default')
        ]);

        $this->assertEquals(201, $project['headers']['status-code']);
        $this->assertIsArray($project['body']['labels']);
        $this->assertCount(0, $project['body']['labels']);
        $projectId = $project['body']['$id'];

        // Apply labels
        $project = $this->client->call(Client::METHOD_PUT, '/projects/' . $projectId . '/labels', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'labels' => ['vip', 'imagine', 'blocked']
        ]);

        $this->assertEquals(200, $project['headers']['status-code']);
        $this->assertIsArray($project['body']['labels']);
        $this->assertCount(3, $project['body']['labels']);
        $this->assertEquals('vip', $project['body']['labels'][0]);
        $this->assertEquals('imagine', $project['body']['labels'][1]);
        $this->assertEquals('blocked', $project['body']['labels'][2]);

        // Update labels
        $project = $this->client->call(Client::METHOD_PUT, '/projects/' . $projectId . '/labels', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'labels' => ['nonvip', 'imagine']
        ]);
        $this->assertEquals(200, $project['headers']['status-code']);
        $this->assertIsArray($project['body']['labels']);
        $this->assertCount(2, $project['body']['labels']);
        $this->assertEquals('nonvip', $project['body']['labels'][0]);
        $this->assertEquals('imagine', $project['body']['labels'][1]);

        // Filter by labels
        $projects = $this->client->call(Client::METHOD_GET, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::contains('labels', ['nonvip'])->toString(),
            ]
        ]);
        $this->assertEquals(200, $projects['headers']['status-code']);
        $this->assertEquals(1, $projects['body']['total']);
        $this->assertEquals($projectId, $projects['body']['projects'][0]['$id']);

        $projects = $this->client->call(Client::METHOD_GET, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::contains('labels', ['vip'])->toString(),
            ]
        ]);
        $this->assertEquals(200, $projects['headers']['status-code']);
        $this->assertEquals(0, $projects['body']['total']);

        $projects = $this->client->call(Client::METHOD_GET, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::contains('labels', ['imagine'])->toString(),
            ]
        ]);
        $this->assertEquals(200, $projects['headers']['status-code']);
        $this->assertEquals(1, $projects['body']['total']);
        $this->assertEquals($projectId, $projects['body']['projects'][0]['$id']);

        $projects = $this->client->call(Client::METHOD_GET, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::contains('labels', ['nonvip', 'imagine'])->toString(),
            ]
        ]);
        $this->assertEquals(200, $projects['headers']['status-code']);
        $this->assertEquals(1, $projects['body']['total']);
        $this->assertEquals($projectId, $projects['body']['projects'][0]['$id']);

        // Setup: Second project with only imagine label
        $project = $this->client->call(Client::METHOD_POST, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'projectId' => ID::unique(),
            'name' => 'Test project - Labels 2',
            'teamId' => $teamId,
            'region' => System::getEnv('_APP_REGION', 'default')
        ]);

        $this->assertEquals(201, $project['headers']['status-code']);
        $this->assertIsArray($project['body']['labels']);
        $this->assertCount(0, $project['body']['labels']);
        $projectId2 = $project['body']['$id'];

        $project = $this->client->call(Client::METHOD_PUT, '/projects/' . $projectId2 . '/labels', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'labels' => ['vip', 'imagine']
        ]);
        $this->assertEquals(200, $project['headers']['status-code']);
        $this->assertIsArray($project['body']['labels']);
        $this->assertCount(2, $project['body']['labels']);
        $this->assertEquals('vip', $project['body']['labels'][0]);
        $this->assertEquals('imagine', $project['body']['labels'][1]);

        // List of imagine has both
        $projects = $this->client->call(Client::METHOD_GET, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::contains('labels', ['imagine'])->toString(),
            ]
        ]);
        $this->assertEquals(200, $projects['headers']['status-code']);
        $this->assertEquals(2, $projects['body']['total']);
        $this->assertEquals($projectId, $projects['body']['projects'][0]['$id']);
        $this->assertEquals($projectId2, $projects['body']['projects'][1]['$id']);

        // List of vip only has second
        $projects = $this->client->call(Client::METHOD_GET, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::contains('labels', ['vip'])->toString(),
            ]
        ]);
        $this->assertEquals(200, $projects['headers']['status-code']);
        $this->assertEquals(1, $projects['body']['total']);
        $this->assertEquals($projectId2, $projects['body']['projects'][0]['$id']);

        // List of vip and imagine has second
        $projects = $this->client->call(Client::METHOD_GET, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::contains('labels', ['vip'])->toString(),
                Query::contains('labels', ['imagine'])->toString(),
            ]
        ]);
        $this->assertEquals(200, $projects['headers']['status-code']);
        $this->assertEquals(1, $projects['body']['total']);
        $this->assertEquals($projectId2, $projects['body']['projects'][0]['$id']);

        // List of vip or imagine has second
        $projects = $this->client->call(Client::METHOD_GET, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::contains('labels', ['vip', 'imagine'])->toString(),
            ]
        ]);
        $this->assertEquals(200, $projects['headers']['status-code']);
        $this->assertEquals(2, $projects['body']['total']);
        $this->assertEquals($projectId, $projects['body']['projects'][0]['$id']);
        $this->assertEquals($projectId2, $projects['body']['projects'][1]['$id']);

        // Cleanup
        $response = $this->client->call(Client::METHOD_DELETE, '/projects/' . $projectId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(204, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_DELETE, '/projects/' . $projectId2, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(204, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_DELETE, '/teams/' . $teamId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(204, $response['headers']['status-code']);
    }

    /**
     * @group ciIgnore
     */
    public function testProjectSpecificPermissionsForListProjects(): void
    {
        $teamId = ID::unique();
        $projectIdA = $this->setupProject([
            'projectId' => ID::unique(),
            'name' => 'Project Test A',
        ], $teamId);
        $projectIdB = $this->setupProject([
            'projectId' => ID::unique(),
            'name' => 'Project Test B',
        ], $teamId, false);

        $testUserEmail = 'test-' . ID::unique() . '@localhost.test';
        $testUserName = 'Test User';

        [ 'membershipId' => $testUserMembershipId ] = $this->setupUserMembership([
            'teamId' => $teamId,
            'email' => $testUserEmail,
            'name' => $testUserName,
            'roles' => ["owner"],
        ]);

        $testCases = [
            [
                'roles' => ["owner"],
                'successProjectIds' => [$projectIdA, $projectIdB],
            ],
            [
                'roles' => ["developer"],
                'successProjectIds' => [$projectIdA, $projectIdB],
            ],
            [
                'roles' => ["project-$projectIdA-owner"],
                'successProjectIds' => [$projectIdA],
            ],
            [
                'roles' => ["project-$projectIdB-owner"],
                'successProjectIds' => [$projectIdB],
            ],
            [
                'roles' => ["project-$projectIdA-developer"],
                'successProjectIds' => [$projectIdA],
            ],
            [
                'roles' => ["project-$projectIdB-developer"],
                'successProjectIds' => [$projectIdB],
            ],
            [
                'roles' => ["developer", "project-$projectIdA-owner"],
                'successProjectIds' => [$projectIdA, $projectIdB],
            ]
        ];

        // Setup session
        $session = $this->client->call(Client::METHOD_POST, '/account/sessions/email', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'email' => $testUserEmail,
            'password' => 'password',
        ]);
        $token = $session['cookies']['a_session_' . $this->getProject()['$id']];

        foreach ($testCases as $testCase) {
            $this->updateMembershipRole($teamId, $testUserMembershipId, $testCase['roles']);

            $response = $this->client->call(Client::METHOD_GET, '/projects', [
                'origin' => 'http://localhost',
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $token,
            ]);

            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertNotEmpty($response['body']);
            $this->assertCount(\count($testCase['successProjectIds']), $response['body']['projects']);

            $returnedProjectIds = \array_column($response['body']['projects'], '$id');
            foreach ($testCase['successProjectIds'] as $projectId) {
                $this->assertContains($projectId, $returnedProjectIds);
            }
        }
    }

    /**
     * @group ciIgnore
     */
    public function testProjectSpecificPermissionsForUpdateProject(): void
    {
        $teamId = ID::unique();
        $projectIdA = $this->setupProject([
            'projectId' => ID::unique(),
            'name' => 'Project Test A',
        ], $teamId);
        $projectIdB = $this->setupProject([
            'projectId' => ID::unique(),
            'name' => 'Project Test B',
        ], $teamId, false);

        $testUserEmail = 'test-' . ID::unique() . '@localhost.test';
        $testUserName = 'Test User';

        [ 'membershipId' => $testUserMembershipId ] = $this->setupUserMembership([
            'teamId' => $teamId,
            'email' => $testUserEmail,
            'name' => $testUserName,
            'roles' => ["owner"],
        ]);

        $testCases = [
            [
                'roles' => ["owner"],
                'successProjectIds' => [$projectIdA, $projectIdB],
                'failureProjectIds' => [],
            ],
            [
                'roles' => ["developer"],
                'successProjectIds' => [$projectIdA, $projectIdB],
                'failureProjectIds' => [],
            ],
            [
                'roles' => ["project-$projectIdA-owner"],
                'successProjectIds' => [$projectIdA],
                'failureProjectIds' => [$projectIdB],
            ],
            [
                'roles' => ["project-$projectIdB-owner"],
                'successProjectIds' => [$projectIdB],
                'failureProjectIds' => [$projectIdA],
            ],
            [
                'roles' => ["project-$projectIdA-developer"],
                'successProjectIds' => [$projectIdA],
                'failureProjectIds' => [$projectIdB],
            ],
            [
                'roles' => ["project-$projectIdB-developer"],
                'successProjectIds' => [$projectIdB],
                'failureProjectIds' => [$projectIdA],
            ],
            [
                'roles' => ["developer", "project-$projectIdA-owner"],
                'successProjectIds' => [$projectIdA, $projectIdB],
                'failureProjectIds' => [],
            ]
        ];

        // Setup session
        $session = $this->client->call(Client::METHOD_POST, '/account/sessions/email', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'email' => $testUserEmail,
            'password' => 'password',
        ]);
        $token = $session['cookies']['a_session_' . $this->getProject()['$id']];

        foreach ($testCases as $testCase) {
            $this->updateMembershipRole($teamId, $testUserMembershipId, $testCase['roles']);

            foreach ($testCase['successProjectIds'] as $projectId) {
                $newProjectName = 'Updated Project Name ' . ID::unique();
                // Success: User should be able to update the project they have access to.
                $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $projectId, [
                    'origin' => 'http://localhost',
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                    'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $token,
                ], [
                    'name' => $newProjectName,
                ]);
                $this->assertEquals(200, $response['headers']['status-code']);
                $this->assertNotEmpty($response['body']);
                $this->assertEquals($newProjectName, $response['body']['name']);
            }

            foreach ($testCase['failureProjectIds'] as $projectId) {
                $newProjectName = 'Updated Project Name ' . ID::unique();
                // Failure: User should not be able to update the project they do not have access to.
                $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $projectId, [
                    'origin' => 'http://localhost',
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                    'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $token,
                ], [
                    'name' => $newProjectName,
                ]);
                $this->assertTrue($response['headers']['status-code'] === 401 || $response['headers']['status-code'] === 404);
            }
        }
    }

    /**
     * @group ciIgnore
     */
    public function testProjectSpecificPermissionsForDeleteProject(): void
    {
        $teamId = ID::unique();
        $projectIdA = $this->setupProject([
            'projectId' => ID::unique(),
            'name' => 'Project Test A',
        ], $teamId);
        $projectIdB = $this->setupProject([
            'projectId' => ID::unique(),
            'name' => 'Project Test B',
        ], $teamId, false);
        $projectIdC = $this->setupProject([
            'projectId' => ID::unique(),
            'name' => 'Project Test C',
        ], $teamId, false);
        $projectIdD = $this->setupProject([
            'projectId' => ID::unique(),
            'name' => 'Project Test D',
        ], $teamId, false);
        $projectIdE = $this->setupProject([
            'projectId' => ID::unique(),
            'name' => 'Project Test E',
        ], $teamId, false);

        $testUserEmail = 'test-' . ID::unique() . '@localhost.test';
        $testUserName = 'Test User';

        [ 'membershipId' => $testUserMembershipId ] = $this->setupUserMembership([
            'teamId' => $teamId,
            'email' => $testUserEmail,
            'name' => $testUserName,
            'roles' => ["owner"],
        ]);

        $testCases = [
            [
                'roles' => ["owner"],
                'successProjectIds' => [$projectIdA],
                'failureProjectIds' => [],
            ],
            [
                'roles' => ["developer"],
                'successProjectIds' => [$projectIdB, $projectIdC],
                'failureProjectIds' => [],
            ],
            [
                'roles' => ["project-$projectIdD-owner"],
                'successProjectIds' => [$projectIdD],
                'failureProjectIds' => [$projectIdE],
            ],
            [
                'roles' => ["project-$projectIdE-owner"],
                'successProjectIds' => [$projectIdE],
                'failureProjectIds' => [],
            ],
        ];

        // Setup session
        $session = $this->client->call(Client::METHOD_POST, '/account/sessions/email', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'email' => $testUserEmail,
            'password' => 'password',
        ]);
        $token = $session['cookies']['a_session_' . $this->getProject()['$id']];

        foreach ($testCases as $testCase) {
            $this->updateMembershipRole($teamId, $testUserMembershipId, $testCase['roles']);

            foreach ($testCase['successProjectIds'] as $projectId) {
                // Success: User should be able to delete the project they have access to.
                $response = $this->client->call(Client::METHOD_DELETE, '/projects/' . $projectId, [
                    'origin' => 'http://localhost',
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                    'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $token,
                ]);
                $this->assertEquals(204, $response['headers']['status-code']);
            }

            foreach ($testCase['failureProjectIds'] as $projectId) {
                // Failure: User should not be able to delete the project they do not have access to.
                $response = $this->client->call(Client::METHOD_DELETE, '/projects/' . $projectId, [
                    'origin' => 'http://localhost',
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                    'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $token,
                ]);
                $this->assertTrue($response['headers']['status-code'] === 401 || $response['headers']['status-code'] === 404);
            }
        }
    }

    /**
     * @group ciIgnore
     * Test project specific permissions for project resources, in this case 'function variables'.
     */
    public function testProjectSpecificPermissionsForProjectResources(): void
    {
        $teamId = ID::unique();
        $projectIdA = $this->setupProject([
            'projectId' => ID::unique(),
            'name' => 'Project Test A',
        ], $teamId);
        $projectIdB = $this->setupProject([
            'projectId' => ID::unique(),
            'name' => 'Project Test B',
        ], $teamId, false);

        $testUserEmail = 'test-' . ID::unique() . '@localhost.test';
        $testUserName = 'Test User';

        [ 'membershipId' => $testUserMembershipId ] = $this->setupUserMembership([
            'teamId' => $teamId,
            'email' => $testUserEmail,
            'name' => $testUserName,
            'roles' => ["owner"],
        ]);

        $testCases = [
            [
                'roles' => ["owner"],
                'successProjectIds' => [$projectIdA, $projectIdB],
                'failureProjectIds' => [],
            ],
            [
                'roles' => ["developer"],
                'successProjectIds' => [$projectIdA, $projectIdB],
                'failureProjectIds' => [],
            ],
            [
                'roles' => ["project-$projectIdA-owner"],
                'successProjectIds' => [$projectIdA],
                'failureProjectIds' => [$projectIdB],
            ],
            [
                'roles' => ["project-$projectIdB-owner"],
                'successProjectIds' => [$projectIdB],
                'failureProjectIds' => [$projectIdA],
            ],
            [
                'roles' => ["project-$projectIdA-developer"],
                'successProjectIds' => [$projectIdA],
                'failureProjectIds' => [$projectIdB],
            ],
            [
                'roles' => ["project-$projectIdB-developer"],
                'successProjectIds' => [$projectIdB],
                'failureProjectIds' => [$projectIdA],
            ],
            [
                'roles' => ["developer", "project-$projectIdA-owner"],
                'successProjectIds' => [$projectIdA, $projectIdB],
                'failureProjectIds' => [],
            ]
        ];

        // Setup session
        $session = $this->client->call(Client::METHOD_POST, '/account/sessions/email', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'email' => $testUserEmail,
            'password' => 'password',
        ]);
        $token = $session['cookies']['a_session_' . $this->getProject()['$id']];

        // Setup functions
        $functionId = ID::unique();
        $this->setupFunction($projectIdA, $functionId, $token);
        $this->setupFunction($projectIdB, $functionId, $token);

        foreach ($testCases as $testCase) {
            $this->updateMembershipRole($teamId, $testUserMembershipId, $testCase['roles']);

            foreach ($testCase['successProjectIds'] as $projectId) {
                $variableId = ID::unique();
                $response = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/variables', [
                    'origin' => 'http://localhost',
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $projectId,
                    'x-appwrite-mode' => 'admin',
                    'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $token,
                ], [
                    'key' => 'APP_TEST_' . $variableId,
                    'value' => 'TESTINGVALUE',
                    'secret' => false
                ]);

                $this->assertEquals(201, $response['headers']['status-code']);
                $this->assertEquals('APP_TEST_' . $variableId, $response['body']['key']);
                $this->assertEquals('TESTINGVALUE', $response['body']['value']);
            }

            foreach ($testCase['failureProjectIds'] as $projectId) {
                $variableId = ID::unique();
                $response = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/variables', [
                    'origin' => 'http://localhost',
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $projectId,
                    'x-appwrite-mode' => 'admin',
                    'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $token,
                ], [
                    'key' => 'APP_TEST_' . $variableId,
                    'value' => 'TESTINGVALUE',
                    'secret' => false
                ]);

                $this->assertTrue($response['headers']['status-code'] === 401 || $response['headers']['status-code'] === 404);
            }
        }
    }
}
