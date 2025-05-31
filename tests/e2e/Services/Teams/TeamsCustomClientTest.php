<?php

namespace Tests\E2E\Services\Teams;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;
use Utopia\CLI\Console;

class TeamsCustomClientTest extends Scope
{
    use TeamsBase;
    use TeamsBaseClient;
    use ProjectCustom;
    use SideClient;

    /**
     * @depends testGetTeamMemberships
     */
    public function testGetMembershipPrivacy($data)
    {
        $teamUid = $data['teamUid'] ?? '';

        $projectId = $this->getProject()['$id'];

        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $projectId . '/auth/memberships-privacy', array_merge([
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
         * Test that sensitive fields are hidden
         */
        $response = $this->client->call(Client::METHOD_GET, '/teams/' . $teamUid . '/memberships', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertIsInt($response['body']['total']);
        $this->assertNotEmpty($response['body']['memberships'][0]['$id']);

        // Assert that sensitive fields are not present
        $this->assertEmpty($response['body']['memberships'][0]['userName']);
        $this->assertEmpty($response['body']['memberships'][0]['userEmail']);
        $this->assertFalse($response['body']['memberships'][0]['mfa']);

        /**
         * Update project settings to show sensitive fields
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $projectId . '/auth/memberships-privacy', array_merge([
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
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertIsInt($response['body']['total']);
        $this->assertNotEmpty($response['body']['memberships'][0]['$id']);

        // Assert that sensitive fields are present
        $this->assertNotEmpty($response['body']['memberships'][0]['userName']);
        $this->assertNotEmpty($response['body']['memberships'][0]['userEmail']);
        $this->assertArrayHasKey('mfa', $response['body']['memberships'][0]);

        /**
         * Update project settings to show only MFA
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $this->getProject()['$id'] . '/auth/memberships-privacy', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ]), [
            'userName' => false,
            'userEmail' => false,
            'mfa' => true,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        /**
         * Test that sensitive fields are not shown
         */
        $response = $this->client->call(Client::METHOD_GET, '/teams/' . $teamUid . '/memberships', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertIsInt($response['body']['total']);
        $this->assertNotEmpty($response['body']['memberships'][0]['$id']);

        // Assert that sensitive fields are present
        $this->assertEmpty($response['body']['memberships'][0]['userName']);
        $this->assertEmpty($response['body']['memberships'][0]['userEmail']);
        $this->assertArrayHasKey('mfa', $response['body']['memberships'][0]);
    }

    /**
     * @depends testUpdateTeamMembership
     */
    public function testTeamsInviteHTMLInjection($data): array
    {
        $teamUid = $data['teamUid'] ?? '';
        $email = uniqid() . 'friend@localhost.test';
        $name = 'Friend User';
        $password = 'password';

        // Create a user account before we create a invite so we can check if the user has permissions when it shouldn't
        $user = $this->client->call(Client::METHOD_POST, '/account', [
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console'], [
                'userId' => 'unique()',
                'email' => $email,
                'password' => $password,
                'name' => $name,
            ], false);

        $this->assertEquals(201, $user['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/teams/' . $teamUid . '/memberships', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'email' => $email,
            'name' => $name,
            'roles' => ['admin', 'editor'],
            'url' => 'http://localhost:5000/join-us\"></a><h1>INJECTED</h1>'
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        $email = $this->getLastEmail();
        Console::log(json_encode([
            'testTeamsInviteHTMLInjection' => $email
        ], JSON_PRETTY_PRINT));

        $encoded = 'http://localhost:5000/join-us\&quot;&gt;&lt;/a&gt;&lt;h1&gt;INJECTED&lt;/h1&gt;?';

        $this->assertStringNotContainsString('<h1>INJECTED</h1>', $email['html']);
        $this->assertStringContainsString($encoded, $email['html']);

        $response = $this->client->call(Client::METHOD_DELETE, '/teams/' . $teamUid . '/memberships/'.$response['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        $this->assertEquals(204, $response['headers']['status-code']);


        return $data;
    }
}
