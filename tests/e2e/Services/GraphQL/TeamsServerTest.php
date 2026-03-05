<?php

namespace Tests\E2E\Services\GraphQL;

use PHPUnit\Framework\Attributes\Group;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Helpers\ID;

class TeamsServerTest extends Scope
{
    use ProjectCustom;
    use Base;
    use SideServer;

    private static array $cachedTeam = [];
    private static array $cachedMembership = [];
    private static array $cachedTeamWithPrefs = [];

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

        $membership = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertIsArray($membership['body']['data']);
        $this->assertArrayNotHasKey('errors', $membership['body']);
        $membership = $membership['body']['data']['teamsCreateMembership'];
        $this->assertEquals($team['_id'], $membership['teamId']);
        $this->assertEquals(['developer'], $membership['roles']);

        static::$cachedMembership[$key] = $membership;
        return $membership;
    }

    protected function setupTeamWithPrefs(): array
    {
        $key = $this->getProject()['$id'];
        if (!empty(static::$cachedTeamWithPrefs[$key])) {
            return static::$cachedTeamWithPrefs[$key];
        }

        $team = $this->setupTeam();

        // Get the team first
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::GET_TEAM);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'teamId' => $team['_id'],
            ],
        ];

        $teamResult = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertIsArray($teamResult['body']['data']);
        $this->assertArrayNotHasKey('errors', $teamResult['body']);
        $fetchedTeam = $teamResult['body']['data']['teamsGet'];

        // Update preferences
        $query = $this->getQuery(self::UPDATE_TEAM_PREFERENCES);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'teamId' => $fetchedTeam['_id'],
                'prefs' => [
                    'key' => 'value'
                ]
            ],
        ];

        $prefs = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertIsArray($prefs['body']['data']);
        $this->assertArrayNotHasKey('errors', $prefs['body']);
        $this->assertIsArray($prefs['body']['data']['teamsUpdatePrefs']);
        $this->assertEquals('{"key":"value"}', $prefs['body']['data']['teamsUpdatePrefs']['data']);

        static::$cachedTeamWithPrefs[$key] = $fetchedTeam;
        return $fetchedTeam;
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

        return $team;
    }

    public function testUpdateTeamPrefs()
    {
        $team = $this->setupTeamWithPrefs();
        $this->assertIsArray($team);
    }

    public function testGetTeamPreferences()
    {
        $team = $this->setupTeamWithPrefs();

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::GET_TEAM_PREFERENCES);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'teamId' => $team['_id'],
            ]
        ];

        $prefs = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertIsArray($prefs['body']['data']);
        $this->assertArrayNotHasKey('errors', $prefs['body']);
        $this->assertIsArray($prefs['body']['data']['teamsGetPrefs']);
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

    public function testUpdateTeam()
    {
        $team = $this->setupTeam();

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::UPDATE_TEAM_NAME);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'teamId' => $team['_id'],
                'name' => 'New Name',
            ],
        ];

        $team = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertIsArray($team['body']['data']);
        $this->assertArrayNotHasKey('errors', $team['body']);
        $team = $team['body']['data']['teamsUpdateName'];
        $this->assertEquals('New Name', $team['name']);
    }

    public function testUpdateTeamMembershipRoles()
    {
        $team = $this->setupTeam();
        $membership = $this->setupMembership();

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::UPDATE_TEAM_MEMBERSHIP);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'teamId' => $team['_id'],
                'membershipId' => $membership['_id'],
                'roles' => ['developer', 'admin'],
            ],
        ];

        $membership = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertIsArray($membership['body']['data']);
        $this->assertArrayNotHasKey('errors', $membership['body']);
        $membership = $membership['body']['data']['teamsUpdateMembership'];
        $this->assertEquals(['developer', 'admin'], $membership['roles']);
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

    #[Group('cl-ignore')]
    public function testDeleteTeam()
    {
        // Create a fresh team for deletion test
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::CREATE_TEAM);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'teamId' => ID::unique(),
                'name' => 'Team To Delete',
                'roles' => ['admin', 'developer', 'guest'],
            ],
        ];

        $team = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $team = $team['body']['data']['teamsCreate'];

        $query = $this->getQuery(self::DELETE_TEAM);
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

        $this->assertIsNotArray($team['body']);
        $this->assertEquals(204, $team['headers']['status-code']);
    }
}
