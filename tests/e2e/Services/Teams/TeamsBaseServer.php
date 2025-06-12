<?php

namespace Tests\E2E\Services\Teams;

use Tests\E2E\Client;
use Utopia\Database\Validator\Datetime as DatetimeValidator;

trait TeamsBaseServer
{
    /**
     * @depends testCreateTeam
     */
    public function testGetTeamMemberships($data): array
    {
        $id = $data['teamUid'] ?? '';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/teams/' . $id . '/memberships', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(0, $response['body']['total']);

        /**
         * Test for FAILURE
         */

        return $data;
    }

    /**
     * @depends testCreateTeamMembership
     */
    public function testGetTeamMembership($data): void
    {
        $teamUid = $data['teamUid'] ?? '';
        $membershipUid = $data['membershipUid'] ?? '';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/teams/' . $teamUid . '/memberships/' . $membershipUid, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertNotEmpty($response['body']['userId']);
        $this->assertFalse($response['body']['mfa']);
        $this->assertNotEmpty($response['body']['userName']);
        $this->assertNotEmpty($response['body']['userEmail']);
        $this->assertNotEmpty($response['body']['teamId']);
        $this->assertNotEmpty($response['body']['teamName']);
        $this->assertCount(2, $response['body']['roles']);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($response['body']['joined'])); // is null in DB
        $this->assertEquals(true, $response['body']['confirm']);

        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $this->getProject()['$id'] . '/auth/memberships-privacy', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ]), [
            'userName' => false,
            'userEmail' => false,
            'mfa' => false,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        /**
         * Test that sensitive fields are not hidden, as we are on console
         */
        $response = $this->client->call(Client::METHOD_GET, '/teams/' . $teamUid . '/memberships', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertIsInt($response['body']['total']);
        $this->assertNotEmpty($response['body']['memberships'][0]['$id']);

        // Assert that sensitive fields are present
        $this->assertNotEmpty($response['body']['memberships'][0]['userName']);
        $this->assertNotEmpty($response['body']['memberships'][0]['userEmail']);
        $this->assertArrayHasKey('mfa', $response['body']['memberships'][0]);

        /**
         * Update project settings to show sensitive fields
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $this->getProject()['$id'] . '/auth/memberships-privacy', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ]), [
            'userName' => true,
            'userEmail' => true,
            'mfa' => true,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        /**
         * Test that sensitive fields are shown
         */
        $response = $this->client->call(Client::METHOD_GET, '/teams/' . $teamUid . '/memberships', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertIsInt($response['body']['total']);
        $this->assertNotEmpty($response['body']['memberships'][0]['$id']);

        // Assert that sensitive fields are present
        $this->assertNotEmpty($response['body']['memberships'][0]['userName']);
        $this->assertNotEmpty($response['body']['memberships'][0]['userEmail']);
        $this->assertArrayHasKey('mfa', $response['body']['memberships'][0]);

        /**
         * Test for FAILURE
         */

        $response = $this->client->call(Client::METHOD_GET, '/teams/' . $teamUid . '/memberships/' . $membershipUid . 'dasdasd', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(404, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/teams/' . $teamUid . '/memberships/' . $membershipUid, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);
    }

    /**
     * @depends testCreateTeam
     */
    public function testCreateTeamMembership($data): array
    {
        $teamUid = $data['teamUid'] ?? '';
        $teamName = $data['teamName'] ?? '';
        $email = uniqid() . 'friend@localhost.test';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/teams/' . $teamUid . '/memberships', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'email' => $email,
            'name' => 'Friend User',
            'roles' => ['admin', 'editor'],
            'url' => 'http://localhost:5000/join-us#title'
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertNotEmpty($response['body']['userId']);
        $this->assertEquals('Friend User', $response['body']['userName']);
        $this->assertEquals($email, $response['body']['userEmail']);
        $this->assertNotEmpty($response['body']['teamId']);
        $this->assertCount(2, $response['body']['roles']);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($response['body']['joined']));
        $this->assertEquals(true, $response['body']['confirm']);

        $userUid = $response['body']['userId'];
        $membershipUid = $response['body']['$id'];

        /**
         * Test for FAILURE
         */

        $response = $this->client->call(Client::METHOD_POST, '/teams/' . $teamUid . '/memberships', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'email' => $email,
            'name' => 'Friend User',
            'roles' => ['admin', 'editor'],
            'url' => 'http://localhost:5000/join-us#title'
        ]);

        $this->assertEquals(409, $response['headers']['status-code']); // membership already created

        $response = $this->client->call(Client::METHOD_POST, '/teams/' . $teamUid . '/memberships', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'email' => 'dasdkaskdjaskdjasjkd',
            'name' => 'Friend User',
            'roles' => ['admin', 'editor'],
            'url' => 'http://localhost:5000/join-us#title'
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/teams/' . $teamUid . '/memberships', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'email' => $email,
            'name' => 'Friend User',
            'roles' => 'bad string',
            'url' => 'http://localhost:5000/join-us#title'
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/teams/' . $teamUid . '/memberships', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'email' => $email,
            'name' => 'Friend User',
            'roles' => ['admin', 'editor'],
            'url' => 'http://example.com/join-us#title' // bad url
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        return [
            'teamUid' => $teamUid,
            'userUid' => $userUid,
            'membershipUid' => $membershipUid
        ];
    }

    /**
     * @depends testCreateTeamMembership
     */
    public function testUpdateMembershipRoles($data)
    {
        $teamUid = $data['teamUid'] ?? '';
        $membershipUid = $data['membershipUid'] ?? '';

        /**
         * Test for SUCCESS
         */
        $roles = ['admin', 'editor', 'uncle'];
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
        $this->assertEquals($roles[1], $response['body']['roles'][1]);
        $this->assertEquals($roles[2], $response['body']['roles'][2]);


        /**
         * Test for FAILURE
         */
        $apiKey = $this->getNewKey(['teams.read']);
        $roles = ['admin', 'editor', 'uncle'];
        $response = $this->client->call(Client::METHOD_PATCH, '/teams/' . $teamUid . '/memberships/' . $membershipUid, [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $apiKey
        ], [
            'roles' => $roles
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);

        return $data;
    }

    /**
     * @depends testUpdateMembershipRoles
     */
    public function testDeleteUserUpdatesTeamMembershipCount($data)
    {
        $teamUid = $data['teamUid'] ?? '';
        $userUid = $data['userUid'] ?? '';

        /** Get Team Count */
        $response = $this->client->call(Client::METHOD_GET, '/teams/' . $teamUid, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals('Arsenal', $response['body']['name']);
        $this->assertEquals(1, $response['body']['total']);
        $this->assertIsInt($response['body']['total']);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($response['body']['$createdAt']));

        /** Delete User */
        $user = $this->client->call(Client::METHOD_DELETE, '/users/' . $userUid, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($user['headers']['status-code'], 204);

        /** Wait for deletes worker to delete membership and update team membership count */
        sleep(5);

        /** Get Team Count */
        $response = $this->client->call(Client::METHOD_GET, '/teams/' . $teamUid, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals('Arsenal', $response['body']['name']);
        $this->assertEquals(0, $response['body']['total']);
        $this->assertIsInt($response['body']['total']);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($response['body']['$createdAt']));
    }
}
