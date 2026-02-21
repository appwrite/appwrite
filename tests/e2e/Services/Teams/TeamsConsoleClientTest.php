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
     * @depends testCreateTeam
     */
    public function testTeamCreateMembershipConsole($data): array
    {
        $teamUid = $data['teamUid'] ?? '';
        $email = uniqid() . 'friend@localhost.test';
        $name = 'Friend User';

        $response = $this->client->call(Client::METHOD_POST, '/teams/' . $teamUid . '/memberships', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'email' => $email,
            'name' => $name,
            'roles' => ['developer'],
            'url' => 'http://example.com/join-us#title' // bad url
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        return $data;
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
        $developer = $this->client->call(Client::METHOD_POST, '/teams/' . $teamUid . '/memberships', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'email' => $email,
            'name' => $name,
            'roles' => ['developer'],
            'url' => 'http://localhost:5000/join-us#title'
        ]);

        $developerUserId = $developer['body']['$id'];
        $this->assertEquals(201, $developer['headers']['status-code']);

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

        $ownerMembershipUid = $response['body']['memberships'][0]['$id'];

        $response = $this->client->call(Client::METHOD_DELETE, '/teams/' . $teamUid . '/memberships/' . $ownerMembershipUid, array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(400, $response['headers']['status-code']);
        $this->assertEquals('membership_deletion_prohibited', $response['body']['type']);
        $this->assertEquals('There must be at least one owner in the organization.', $response['body']['message']);

        // Remove the excess developer member to reduce the membership count in `TeamsBaseClient` tests.
        // This is necessary because the only owner cannot be removed in the console project / top level team / organization.
        $response = $this->client->call(Client::METHOD_DELETE, '/teams/' . $teamUid . '/memberships/' . $developerUserId, array_merge([
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
         * Test for unknown team
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/teams/' . 'abc' . '/memberships/' . $membershipUid, array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'roles' => ['developer']
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
            'roles' => ['developer']
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
            'roles' => ['developer']
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);
        $this->assertEquals('User is not allowed to modify roles', $response['body']['message']);

        return $data;
    }

    /**
     * @depends testUpdateTeamMembershipRoles
     */
    public function testDeleteTeamMembership($data): array
    {
        $teamUid = $data['teamUid'] ?? '';
        $membershipUid = $data['membershipUid'] ?? '';
        $session = $data['session'] ?? '';

        $response = $this->client->call(Client::METHOD_GET, '/teams/' . $teamUid . '/memberships', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(3, $response['body']['total']);

        $ownerMembershipUid = $response['body']['memberships'][0]['$id'];

        /**
         * Test deleting a membership that does not exists
         */
        $response = $this->client->call(Client::METHOD_DELETE, '/teams/' . $teamUid . '/memberships/dne', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]);

        $this->assertEquals(404, $response['headers']['status-code']);

        /**
         * Test deleting another user's membership
         */
        $response = $this->client->call(Client::METHOD_DELETE, '/teams/' . $teamUid . '/memberships/' . $ownerMembershipUid, [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);
        $this->assertEquals('The current user is not authorized to perform the requested action.', $response['body']['message']);

        $response = $this->client->call(Client::METHOD_DELETE, '/teams/' . $teamUid . '/memberships/' . $membershipUid, [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]);

        $this->assertEquals(204, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/teams/' . $teamUid . '/memberships', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(2, $response['body']['total']);

        /**
         * Test for when the owner tries to delete their membership
         */
        $response = $this->client->call(Client::METHOD_DELETE, '/teams/' . $teamUid . '/memberships/' . $ownerMembershipUid, array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(400, $response['headers']['status-code']);
        $this->assertEquals('membership_deletion_prohibited', $response['body']['type']);
        $this->assertEquals('There must be at least one owner in the organization.', $response['body']['message']);

        $response = $this->client->call(Client::METHOD_GET, '/teams/' . $teamUid . '/memberships/' . $ownerMembershipUid, array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);

        return [];
    }
}
