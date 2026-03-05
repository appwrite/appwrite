<?php

namespace Tests\E2E\Services\GraphQL;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;
use Utopia\Database\Helpers\ID;

class TeamsClientTest extends Scope
{
    use ProjectCustom;
    use Base;
    use SideClient;

    private static array $cachedTeam = [];
    private static array $cachedMembership = [];

    protected function setupTeam(): array
    {
        $key = $this->getProject()['$id'];
        if (!empty(static::$cachedTeam[$key])) {
            return static::$cachedTeam[$key];
        }

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::CREATE_TEAM);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'teamId' => ID::unique(),
                'name' => 'Team Name',
                'roles' => ['admin', 'developer', 'guest'],
            ],
        ];

        $team = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertIsArray($team['body']['data']);
        $this->assertArrayNotHasKey('errors', $team['body']);
        $team = $team['body']['data']['teamsCreate'];
        $this->assertEquals('Team Name', $team['name']);

        static::$cachedTeam[$key] = $team;
        return $team;
    }

    protected function setupMembership(): array
    {
        $key = $this->getProject()['$id'];
        if (!empty(static::$cachedMembership[$key])) {
            return static::$cachedMembership[$key];
        }

        $team = $this->setupTeam();

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::CREATE_TEAM_MEMBERSHIP);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'teamId' => $team['_id'],
                'email' => 'user@appwrite.io',
                'roles' => ['developer'],
                'url' => 'http://localhost/membership',
            ],
        ];

        $membership = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], $graphQLPayload);

        $this->assertIsArray($membership['body']['data']);
        $this->assertArrayNotHasKey('errors', $membership['body']);
        $membership = $membership['body']['data']['teamsCreateMembership'];
        $this->assertEquals($team['_id'], $membership['teamId']);
        $this->assertEquals(['developer'], $membership['roles']);

        static::$cachedMembership[$key] = $membership;
        return $membership;
    }

    public function testCreateTeam(): void
    {
        $team = $this->setupTeam();
        $this->assertEquals('Team Name', $team['name']);
    }

    public function testCreateTeamMembership(): void
    {
        $membership = $this->setupMembership();
        $this->assertEquals(['developer'], $membership['roles']);
    }

    public function testGetTeams()
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::GET_TEAMS);
        $graphQLPayload = [
            'query' => $query,
        ];

        $teams = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertIsArray($teams['body']['data']);
        $this->assertArrayNotHasKey('errors', $teams['body']);
    }

    public function testGetTeam()
    {
        $team = $this->setupTeam();

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::GET_TEAM);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'teamId' => $team['_id'],
            ],
        ];

        $team = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertIsArray($team['body']['data']);
        $this->assertArrayNotHasKey('errors', $team['body']);
        $team = $team['body']['data']['teamsGet'];
        $this->assertIsArray($team);
    }

    public function testGetTeamMemberships()
    {
        $team = $this->setupTeam();

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::GET_TEAM_MEMBERSHIPS);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'teamId' => $team['_id'],
            ],
        ];

        $memberships = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertIsArray($memberships['body']['data']);
        $this->assertArrayNotHasKey('errors', $memberships['body']);
        $this->assertIsArray($memberships['body']['data']['teamsListMemberships']);
    }

    public function testGetTeamMembership()
    {
        $team = $this->setupTeam();
        $membership = $this->setupMembership();

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::GET_TEAM_MEMBERSHIP);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'teamId' => $team['_id'],
                'membershipId' => $membership['_id'],
            ],
        ];

        $membership = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertIsArray($membership['body']['data']['teamsGetMembership']);
        $this->assertArrayNotHasKey('errors', $membership['body']);
    }

    public function testDeleteTeamMembership()
    {
        $team = $this->setupTeam();
        $membership = $this->setupMembership();

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::DELETE_TEAM_MEMBERSHIP);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'teamId' => $team['_id'],
                'membershipId' => $membership['_id'],
            ],
        ];

        $team = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertIsNotArray($team['body']);
        $this->assertEquals(204, $team['headers']['status-code']);

        // Clear cache after deletion
        $key = $this->getProject()['$id'];
        static::$cachedMembership[$key] = [];
    }
}
