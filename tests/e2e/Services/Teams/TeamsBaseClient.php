<?php

namespace Tests\E2E\Services\Teams;

use Tests\E2E\Client;

trait TeamsBaseClient
{
    /**
     * @depends testCreateTeam
     */
    public function testGetTeamMemberships($data):array
    {
        $teamUid = $data['teamUid'] ?? '';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/teams/'.$teamUid.'/memberships', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertIsInt($response['body']['sum']);
        $this->assertNotEmpty($response['body']['memberships'][0]['$id']);
        $this->assertEquals($this->getUser()['name'], $response['body']['memberships'][0]['name']);
        $this->assertEquals($this->getUser()['email'], $response['body']['memberships'][0]['email']);
        $this->assertEquals('owner', $response['body']['memberships'][0]['roles'][0]);

        /**
         * Test for FAILURE
         */

        return $data;
    }

    /**
     * @depends testCreateTeam
     */
    public function testCreateTeamMembership($data):array
    {
        $teamUid = $data['teamUid'] ?? '';
        $teamName = $data['teamName'] ?? '';
        $email = uniqid().'friend@localhost.test';
        $name = 'Friend User';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/teams/'.$teamUid.'/memberships', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'email' => $email,
            'name' => $name,
            'roles' => ['admin', 'editor'],
            'url' => 'http://localhost:5000/join-us#title'
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertNotEmpty($response['body']['userId']);
        $this->assertNotEmpty($response['body']['teamId']);
        $this->assertCount(2, $response['body']['roles']);
        $this->assertIsInt($response['body']['joined']);
        $this->assertEquals(false, $response['body']['confirm']);

        $lastEmail = $this->getLastEmail();

        $this->assertEquals($email, $lastEmail['to'][0]['address']);
        $this->assertEquals($name, $lastEmail['to'][0]['name']);
        $this->assertEquals('Invitation to '.$teamName.' Team at '.$this->getProject()['name'], $lastEmail['subject']);

        $secret = substr($lastEmail['text'], strpos($lastEmail['text'], '&secret=', 0) + 8, 256);
        $membershipUid = substr($lastEmail['text'], strpos($lastEmail['text'], '?membershipId=', 0) + 14, 13);
        $userUid = substr($lastEmail['text'], strpos($lastEmail['text'], '&userId=', 0) + 8, 13);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_POST, '/teams/'.$teamUid.'/memberships', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'email' => 'dasdkaskdjaskdjasjkd',
            'name' => $name,
            'roles' => ['admin', 'editor'],
            'url' => 'http://localhost:5000/join-us#title'
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/teams/'.$teamUid.'/memberships', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'email' => $email,
            'name' => $name,
            'roles' => 'bad string',
            'url' => 'http://localhost:5000/join-us#title'
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/teams/'.$teamUid.'/memberships', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'email' => $email,
            'name' => $name,
            'roles' => ['admin', 'editor'],
            'url' => 'http://example.com/join-us#title' // bad url
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        return [
            'teamUid' => $teamUid,
            'secret' => $secret,
            'membershipUid' => $membershipUid,
            'userUid' => $userUid,
            'email' => $email,
            'name' => $name
        ];
    }

    /**
     * @depends testCreateTeamMembership
     */
    public function testUpdateTeamMembership($data):array
    {
        $teamUid = $data['teamUid'] ?? '';
        $secret = $data['secret'] ?? '';
        $membershipUid = $data['membershipUid'] ?? '';
        $userUid = $data['userUid'] ?? '';
        $email = $data['email'] ?? '';
        $name = $data['name'] ?? '';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/teams/'.$teamUid.'/memberships/'.$membershipUid.'/status', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'secret' => $secret,
            'userId' => $userUid,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertNotEmpty($response['body']['userId']);
        $this->assertNotEmpty($response['body']['teamId']);
        $this->assertCount(2, $response['body']['roles']);
        $this->assertIsInt($response['body']['joined']);
        $this->assertEquals(true, $response['body']['confirm']);
        $session = $this->client->parseCookie((string)$response['headers']['set-cookie'])['a_session_'.$this->getProject()['$id']];
        $data['session'] = $session;

        /**
         * New User tries to update password without old password -> SHOULD PASS
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/account/password', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $session,
        ]), [
            'password' => 'new-password'
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertIsArray($response['body']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertIsNumeric($response['body']['registration']);
        $this->assertEquals($response['body']['email'], $email);
        $this->assertEquals($response['body']['name'], $name);

        /**
         * New User again tries to update password with ONLY new password -> SHOULD FAIL
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/account/password', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $session,
        ]), [
            'password' => 'new-password',
        ]);
        $this->assertEquals(401, $response['headers']['status-code']);

        /**
         * New User tries to update password by passing both old and new password -> SHOULD PASS
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/account/password', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $session,
        ]), [
            'password' => 'newer-password',
            'oldPassword' => 'new-password'
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertIsArray($response['body']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertIsNumeric($response['body']['registration']);
        $this->assertEquals($response['body']['email'], $email);
        $this->assertEquals($response['body']['name'], $name);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/teams/'.$teamUid.'/memberships/'.$membershipUid.'/status', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'secret' => 'sdasdasd',
            'userId' => $userUid,
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_PATCH, '/teams/'.$teamUid.'/memberships/'.$membershipUid.'/status', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'secret' => '',
            'userId' => $userUid,
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_PATCH, '/teams/'.$teamUid.'/memberships/'.$membershipUid.'/status', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'secret' => $secret,
            'userId' => 'sdasd',
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_PATCH, '/teams/'.$teamUid.'/memberships/'.$membershipUid.'/status', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'secret' => $secret,
            'userId' => '',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        return $data;
    }

    /**
     * @depends testUpdateTeamMembership
     */
    public function testUpdateTeamMembershipRoles($data):array
    {
        $teamUid = $data['teamUid'] ?? '';
        $membershipUid = $data['membershipUid'] ?? '';
        $session = $data['session'] ?? '';

        /**
         * Test for SUCCESS
         */
        $roles = ['admin', 'editor', 'uncle'];
        $response = $this->client->call(Client::METHOD_PATCH, '/teams/'.$teamUid.'/memberships/'.$membershipUid, array_merge([
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
         * Test for unknown team
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/teams/'.'abc'.'/memberships/'.$membershipUid, array_merge([
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
        $response = $this->client->call(Client::METHOD_PATCH, '/teams/'.$teamUid.'/memberships/'.'abc', array_merge([
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
        $response = $this->client->call(Client::METHOD_PATCH, '/teams/'.$teamUid.'/memberships/'.$membershipUid, [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $session,
        ], [
            'roles' => $roles
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);
        $this->assertEquals('User is not allowed to modify roles', $response['body']['message']);
        
        return $data;
    }

     /**
     * @depends testUpdateTeamMembershipRoles
     */
    public function testDeleteTeamMembership($data):array
    {
        $teamUid = $data['teamUid'] ?? '';
        $membershipUid = $data['membershipUid'] ?? '';
        
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_DELETE, '/teams/'.$teamUid.'/memberships/'.$membershipUid, array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(204, $response['headers']['status-code']);
        $this->assertEmpty($response['body']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/teams/'.$teamUid.'/memberships/'.$membershipUid, array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(1, $response['body']['memberships']);

        return [];
    }

}