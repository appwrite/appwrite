<?php

namespace Tests\E2E\Services\Organization;

use Appwrite\Extend\Exception;
use Tests\E2E\Client;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;
use Utopia\System\System;

trait ProjectsBase
{
    private static array $cachedOrganization = [];
    private static array $cachedProjectData = [];

    /**
     * Setup and cache an organization (team) for organization endpoint tests.
     */
    protected function setupOrganization(): array
    {
        if (!empty(self::$cachedOrganization)) {
            return self::$cachedOrganization;
        }

        $teamId = ID::unique();
        $team = null;
        for ($i = 0; $i < 3; $i++) {
            $team = $this->client->call(Client::METHOD_POST, '/teams', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()), [
                'teamId' => $teamId,
                'name' => 'Organization Test',
            ]);
            if (\in_array($team['headers']['status-code'], [201, 409])) {
                break;
            }
            \usleep(500000);
        }
        $this->assertContains($team['headers']['status-code'], [201, 409], 'Setup organization (team) failed');

        self::$cachedOrganization = [
            'teamId' => $team['body']['$id'] ?? $teamId,
        ];

        return self::$cachedOrganization;
    }

    protected function getOrganizationHeaders(): array
    {
        $organization = $this->setupOrganization();

        return array_merge($this->getHeaders(), [
            'x-appwrite-organization' => $organization['teamId'],
        ]);
    }

    /**
     * Setup and cache a project created via organization endpoints.
     */
    protected function setupOrganizationProject(): array
    {
        if (!empty(self::$cachedProjectData)) {
            return self::$cachedProjectData;
        }

        $project = null;
        for ($i = 0; $i < 3; $i++) {
            $project = $this->client->call(Client::METHOD_POST, '/v1/organization/projects', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getOrganizationHeaders()), [
                'projectId' => ID::unique(),
                'name' => 'Organization Project Test',
                'region' => System::getEnv('_APP_REGION', 'default'),
            ]);
            if ($project['headers']['status-code'] === 201) {
                break;
            }
            \usleep(500000);
        }
        $this->assertEquals(201, $project['headers']['status-code'], 'Setup organization project failed');

        self::$cachedProjectData = [
            'projectId' => $project['body']['$id'],
            'teamId' => $this->setupOrganization()['teamId'],
        ];

        return self::$cachedProjectData;
    }

    public function testCreateProject(): void
    {
        $organization = $this->setupOrganization();
        $teamId = $organization['teamId'];

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/v1/organization/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getOrganizationHeaders()), [
            'projectId' => ID::unique(),
            'name' => 'Organization Project Test',
            'region' => System::getEnv('_APP_REGION', 'default'),
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals('Organization Project Test', $response['body']['name']);
        $this->assertEquals($teamId, $response['body']['teamId']);
        $this->assertEquals(PROJECT_STATUS_ACTIVE, $response['body']['status']);
        $this->assertArrayHasKey('platforms', $response['body']);
        $this->assertArrayHasKey('webhooks', $response['body']);
        $this->assertArrayHasKey('keys', $response['body']);

        /**
         * Test for FAILURE - missing organization header
         */
        $response = $this->client->call(Client::METHOD_POST, '/v1/organization/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'projectId' => ID::unique(),
            'name' => 'Organization Project Test',
            'region' => System::getEnv('_APP_REGION', 'default'),
        ]);

        $this->assertEquals(404, $response['headers']['status-code']);

        /**
         * Test for FAILURE - empty name
         */
        $response = $this->client->call(Client::METHOD_POST, '/v1/organization/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getOrganizationHeaders()), [
            'projectId' => ID::unique(),
            'name' => '',
            'region' => System::getEnv('_APP_REGION', 'default'),
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
    }

    public function testCreateDuplicateProject(): void
    {
        $organization = $this->setupOrganization();
        $projectId = ID::unique();

        $response = $this->client->call(Client::METHOD_POST, '/v1/organization/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getOrganizationHeaders()), [
            'projectId' => $projectId,
            'name' => 'Original Organization Project',
            'region' => System::getEnv('_APP_REGION', 'default'),
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        /**
         * Test for FAILURE - duplicate project ID
         */
        $response = $this->client->call(Client::METHOD_POST, '/v1/organization/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getOrganizationHeaders()), [
            'projectId' => $projectId,
            'name' => 'Duplicate Organization Project',
            'region' => System::getEnv('_APP_REGION', 'default'),
        ]);

        $this->assertEquals(409, $response['headers']['status-code']);
        $this->assertEquals(409, $response['body']['code']);
        $this->assertEquals(Exception::PROJECT_ALREADY_EXISTS, $response['body']['type']);
    }

    public function testGetProject(): void
    {
        $data = $this->setupOrganizationProject();
        $projectId = $data['projectId'];

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/v1/organization/projects/' . $projectId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getOrganizationHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals($projectId, $response['body']['$id']);
        $this->assertEquals('Organization Project Test', $response['body']['name']);
        $this->assertEquals(PROJECT_STATUS_ACTIVE, $response['body']['status']);
        $this->assertArrayHasKey('platforms', $response['body']);
        $this->assertArrayHasKey('webhooks', $response['body']);
        $this->assertArrayHasKey('keys', $response['body']);

        /**
         * Test for FAILURE - project not found
         */
        $response = $this->client->call(Client::METHOD_GET, '/v1/organization/projects/' . ID::unique(), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getOrganizationHeaders()));

        $this->assertEquals(404, $response['headers']['status-code']);

        /**
         * Test for FAILURE - project from different organization
         */
        $otherTeam = $this->client->call(Client::METHOD_POST, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'teamId' => ID::unique(),
            'name' => 'Other Organization',
        ]);
        $this->assertContains($otherTeam['headers']['status-code'], [201, 409]);
        $otherTeamId = $otherTeam['body']['$id'] ?? $otherTeam['body']['teamId'];

        $otherProject = $this->client->call(Client::METHOD_POST, '/v1/organization/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], array_merge($this->getHeaders(), [
            'x-appwrite-organization' => $otherTeamId,
        ])), [
            'projectId' => ID::unique(),
            'name' => 'Other Organization Project',
            'region' => System::getEnv('_APP_REGION', 'default'),
        ]);
        $this->assertEquals(201, $otherProject['headers']['status-code']);
        $otherProjectId = $otherProject['body']['$id'];

        $response = $this->client->call(Client::METHOD_GET, '/v1/organization/projects/' . $otherProjectId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getOrganizationHeaders()));

        $this->assertEquals(404, $response['headers']['status-code']);
    }

    public function testUpdateProject(): void
    {
        $data = $this->setupOrganizationProject();
        $projectId = $data['projectId'];

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/v1/organization/projects/' . $projectId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getOrganizationHeaders()), [
            'name' => 'Updated Organization Project',
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals($projectId, $response['body']['$id']);
        $this->assertEquals('Updated Organization Project', $response['body']['name']);

        /**
         * Test for FAILURE - project not found
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/v1/organization/projects/' . ID::unique(), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getOrganizationHeaders()), [
            'name' => 'Should Fail',
        ]);

        $this->assertEquals(404, $response['headers']['status-code']);

        /**
         * Test for FAILURE - empty name
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/v1/organization/projects/' . $projectId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getOrganizationHeaders()), [
            'name' => '',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
    }

    public function testDeleteProject(): void
    {
        $organization = $this->setupOrganization();

        $project = $this->client->call(Client::METHOD_POST, '/v1/organization/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getOrganizationHeaders()), [
            'projectId' => ID::unique(),
            'name' => 'Project To Delete',
            'region' => System::getEnv('_APP_REGION', 'default'),
        ]);

        $this->assertEquals(201, $project['headers']['status-code']);
        $projectId = $project['body']['$id'];

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_DELETE, '/v1/organization/projects/' . $projectId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getOrganizationHeaders()));

        $this->assertEquals(204, $response['headers']['status-code']);

        // Verify project is actually deleted
        $response = $this->client->call(Client::METHOD_GET, '/v1/organization/projects/' . $projectId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getOrganizationHeaders()));

        $this->assertEquals(404, $response['headers']['status-code']);

        /**
         * Test for FAILURE - project not found (already deleted)
         */
        $response = $this->client->call(Client::METHOD_DELETE, '/v1/organization/projects/' . $projectId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getOrganizationHeaders()));

        $this->assertEquals(404, $response['headers']['status-code']);
    }

    public function testListProjects(): void
    {
        $organization = $this->setupOrganization();
        $teamId = $organization['teamId'];

        // Create a second project in the same organization
        $project2 = $this->client->call(Client::METHOD_POST, '/v1/organization/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getOrganizationHeaders()), [
            'projectId' => ID::unique(),
            'name' => 'Second Organization Project',
            'region' => System::getEnv('_APP_REGION', 'default'),
        ]);

        $this->assertEquals(201, $project2['headers']['status-code']);
        $project2Id = $project2['body']['$id'];

        /**
         * Test for SUCCESS - basic list
         */
        $response = $this->client->call(Client::METHOD_GET, '/v1/organization/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getOrganizationHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertGreaterThan(0, count($response['body']['projects']));
        $this->assertGreaterThan(0, $response['body']['total']);

        /**
         * Test search queries
         */
        $response = $this->client->call(Client::METHOD_GET, '/v1/organization/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getOrganizationHeaders(), [
            'search' => 'Second Organization Project',
        ]));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertGreaterThan(0, $response['body']['total']);
        $this->assertIsArray($response['body']['projects']);
        $this->assertEquals('Second Organization Project', $response['body']['projects'][0]['name']);

        /**
         * Test pagination with limit
         */
        $response = $this->client->call(Client::METHOD_GET, '/v1/organization/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getOrganizationHeaders()), [
            'queries' => [
                Query::limit(1)->toString(),
            ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(1, $response['body']['projects']);

        /**
         * Test pagination with offset
         */
        $response = $this->client->call(Client::METHOD_GET, '/v1/organization/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getOrganizationHeaders()), [
            'queries' => [
                Query::offset(1)->toString(),
            ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);

        /**
         * Test query by name
         */
        $response = $this->client->call(Client::METHOD_GET, '/v1/organization/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getOrganizationHeaders()), [
            'queries' => [
                Query::equal('name', ['Second Organization Project'])->toString(),
            ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertGreaterThanOrEqual(1, count($response['body']['projects']));
        $this->assertEquals('Second Organization Project', $response['body']['projects'][0]['name']);

        /**
         * Test cursor pagination
         */
        $response = $this->client->call(Client::METHOD_GET, '/v1/organization/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getOrganizationHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['projects']);

        $response = $this->client->call(Client::METHOD_GET, '/v1/organization/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getOrganizationHeaders()), [
            'queries' => [
                Query::cursorAfter(new Document(['$id' => $response['body']['projects'][0]['$id']]))->toString(),
            ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);

        /**
         * Test for FAILURE - invalid cursor
         */
        $response = $this->client->call(Client::METHOD_GET, '/v1/organization/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getOrganizationHeaders()), [
            'queries' => [
                Query::cursorAfter(new Document(['$id' => 'unknown']))->toString(),
            ],
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
    }

    public function testListProjectsQuerySelect(): void
    {
        $data = $this->setupOrganizationProject();
        $projectId = $data['projectId'];

        $response = $this->client->call(Client::METHOD_GET, '/v1/organization/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getOrganizationHeaders()), [
            'queries' => [
                Query::select(['name'])->toString(),
            ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['projects']);
        $this->assertEquals('Organization Project Test', $response['body']['projects'][0]['name']);
    }
}
