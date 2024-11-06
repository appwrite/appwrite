<?php

namespace Tests\E2E\Services\Teams;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectConsole;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;

class TeamsConsoleClientTest extends Scope
{
    use TeamsBase;
    use TeamsBaseClient;
    use ProjectConsole;
    use SideClient;

    /**
     * @depends testGetTeamMemberships
     */
    public function testSensitiveFieldsGetMembership($data)
    {
        $teamUid = $data['teamUid'] ?? '';

        $projectId = $this->getProject()['$id'];

        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $projectId . '/auth/teams-sensitive-attributes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ]), [
            'enabled' => false,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        /**
         * Test that sensitive fields are not hidden, as we are on console
         */
        $response = $this->client->call(Client::METHOD_GET, '/teams/' . $teamUid . '/memberships', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertIsInt($response['body']['total']);
        $this->assertNotEmpty($response['body']['memberships'][0]['$id']);

        // Assert that sensitive fields are present
        $this->assertEquals($this->getUser()['name'], $response['body']['memberships'][0]['userName']);
        $this->assertEquals($this->getUser()['email'], $response['body']['memberships'][0]['userEmail']);
        $this->assertFalse($response['body']['memberships'][0]['mfa']);

        /**
         * Update project settings to show sensitive fields
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $projectId . '/auth/teams-sensitive-attributes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ]), [
            'enabled' => true,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        /**
         * Test that sensitive fields are shown
         */
        $response = $this->client->call(Client::METHOD_GET, '/teams/' . $teamUid . '/memberships', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertIsInt($response['body']['total']);
        $this->assertNotEmpty($response['body']['memberships'][0]['$id']);

        // Assert that sensitive fields are present
        $this->assertEquals($this->getUser()['name'], $response['body']['memberships'][0]['userName']);
        $this->assertEquals($this->getUser()['email'], $response['body']['memberships'][0]['userEmail']);
        $this->assertFalse($response['body']['memberships'][0]['mfa']);
    }

    /**
     * @depends testCreateTeam
     */
    public function testTeamMembershipPerms($data): array
    {
        $teamUid = $data['teamUid'] ?? '';
        $teamName = $data['teamName'] ?? '';
        $email = uniqid() . 'friend@localhost.test';
        $name = 'Friend User';
        $password = 'password';

        // Create a user account before we create a invite so we can check if the user has permissions when it shouldn't
        $user = $this->client->call(Client::METHOD_POST, '/account', [
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console'
        ], [
            'userId' => 'unique()',
            'email' => $email,
            'password' => $password,
            'name' => $name,
        ], false);

        $this->assertEquals(201, $user['headers']['status-code']);

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/teams/' . $teamUid . '/memberships', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'email' => $email,
            'name' => $name,
            'roles' => ['developer'],
            'url' => 'http://localhost:5000/join-us#title'
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        $this->assertEquals(401, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/teams/' . $teamUid . '/memberships', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);

        $ownerMembershipUid = $response['body']['memberships'][1]['$id'];

        $response = $this->client->call(Client::METHOD_DELETE, '/teams/' . $teamUid . '/memberships/' . $ownerMembershipUid, array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        $this->assertEquals(204, $response['headers']['status-code']);

        return $data;
    }

    /** @depends testUpdateTeamMembership */
    public function testUpdateTeamMembershipRoles($data): array
    {
        $teamUid = $data['teamUid'] ?? '';
        $membershipUid = $data['membershipUid'] ?? '';
        $session = $data['session'] ?? '';

        /**
         * Test for SUCCESS
         */
        $roles = ['developer'];
        $response = $this->client->call(Client::METHOD_PATCH, '/teams/' . $teamUid . '/memberships/' . $membershipUid, array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'roles' => $roles
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertNotEmpty($response['body']['userId']);
        $this->assertNotEmpty($response['body']['teamId']);
        $this->assertCount(count($roles), $response['body']['roles']);
        $this->assertEquals($roles[0], $response['body']['roles'][0]);

        /**
         * Test for unknown team
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/teams/' . 'abc' . '/memberships/' . $membershipUid, array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'roles' => $roles
        ]);

        $this->assertEquals(404, $response['headers']['status-code']);

        /**
         * Test for unknown membership ID
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/teams/' . $teamUid . '/memberships/' . 'abc', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'roles' => $roles
        ]);

        $this->assertEquals(404, $response['headers']['status-code']);


        /**
         * Test for when a user other than the owner tries to update membership
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/teams/' . $teamUid . '/memberships/' . $membershipUid, [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ], [
            'roles' => $roles
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);
        $this->assertEquals('User is not allowed to modify roles', $response['body']['message']);

        return $data;
    }
}
