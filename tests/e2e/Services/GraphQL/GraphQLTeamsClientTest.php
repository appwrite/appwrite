<?php

namespace Tests\E2E\Services\GraphQL;

use Tests\E2E\Client;
use Tests\E2E\Scopes\SideClient;

class GraphQLTeamsClientTest extends GraphQLTeamsBase
{
    use SideClient;

    /**
     * @depends testCreateTeam
     * @depends testCreateTeamMembership
     */
    public function testUpdateTeamMembershipStatus($team, $membership)
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$UPDATE_TEAM_MEMBERSHIP_STATUS);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'teamId' => $team['_id'],
                'membershipId' => $membership['_id'],
                'userId' => $membership['userId'],
                'secret' => 'secretkey',
            ],
        ];

        $membership = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertIsArray($membership['body']['data']);
        $this->assertArrayNotHasKey('errors', $membership['body']);
        $membership = $membership['body']['data']['teamsUpdateMembershipStatus'];
        $this->assertEquals('active', $membership['status']);
    }
}