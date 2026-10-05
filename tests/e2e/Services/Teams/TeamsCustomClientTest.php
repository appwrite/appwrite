<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Teams;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;

final class TeamsCustomClientTest extends Scope
{
    use TeamsBase;
    use TeamsBaseClient;
    use ProjectCustom;
    use SideClient;

    public function testCreateMembershipByPhone(): void
    {
        $projectId = $this->getProject()['$id'];
        $team = $this->createTeamHelper('Research & Development');
        $phone = '+1202' . random_int(1000000, 9999999);

        /**
         * Test for SUCCESS
         */
        $membership = $this->client->call(Client::METHOD_POST, '/teams/' . $team['teamUid'] . '/memberships', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'phone' => $phone,
            'roles' => ['developer'],
            'url' => 'http://localhost:5000/join-us?source=sms&campaign=team%20invite#invitation',
        ]);

        $this->assertEquals(201, $membership['headers']['status-code']);
        $this->assertFalse($membership['body']['confirm']);

        $sms = $this->getLastRequestForProject($projectId, Scope::REQUEST_TYPE_SMS, [
            'header_X-Username' => 'username',
            'header_X-Key' => 'password',
            'method' => 'POST',
        ], probe: function (array $request) use ($phone) {
            $this->assertEquals($phone, $request['data']['to'] ?? null);
        });

        $this->assertNotEmpty($sms);
        $url = (string) $sms['data']['message'];
        $this->assertStringNotContainsString('&amp;', $url);
        $this->assertEquals('/join-us', parse_url($url, PHP_URL_PATH));
        $this->assertEquals('invitation', parse_url($url, PHP_URL_FRAGMENT));
        parse_str(parse_url($url, PHP_URL_QUERY), $params);
        $this->assertEquals('sms', $params['source']);
        $this->assertEquals('team invite', $params['campaign']);
        $this->assertEquals($team['teamUid'], $params['teamId']);
        $this->assertEquals($team['teamName'], $params['teamName']);
        $this->assertEquals($membership['body']['$id'], $params['membershipId']);
        $this->assertEquals($membership['body']['userId'], $params['userId']);
        $this->assertNotEmpty($params['secret']);

        $confirmation = $this->client->call(Client::METHOD_PATCH, '/teams/' . $params['teamId'] . '/memberships/' . $params['membershipId'] . '/status', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], [
            'userId' => $params['userId'],
            'secret' => $params['secret'],
        ]);

        $this->assertEquals(200, $confirmation['headers']['status-code']);
        $this->assertTrue($confirmation['body']['confirm']);

        /**
         * Test for FAILURE
         * The invitation cannot be accepted again.
         */
        $confirmation = $this->client->call(Client::METHOD_PATCH, '/teams/' . $params['teamId'] . '/memberships/' . $params['membershipId'] . '/status', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], [
            'userId' => $params['userId'],
            'secret' => $params['secret'],
        ]);

        $this->assertEquals(409, $confirmation['headers']['status-code']);
        $this->assertEquals('membership_already_confirmed', $confirmation['body']['type']);
    }

    public function testGetMembershipPrivacy(): void
    {
        $teamData = $this->createTeamHelper();
        $teamUid = $teamData['teamUid'];

        $projectId = $this->getProject()['$id'];

        // The policy only governs other members, so the team needs one
        $otherEmail = uniqid() . 'foe@localhost.test';
        $otherName = 'Privacy Foe';

        $invite = $this->client->call(Client::METHOD_POST, '/teams/' . $teamUid . '/memberships', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'email' => $otherEmail,
            'name' => $otherName,
            'roles' => ['developer'],
            'url' => 'http://localhost:5000/join-us#title',
        ]);

        $this->assertEquals(201, $invite['headers']['status-code']);
        $otherUid = $invite['body']['$id'];

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
        $memberships = array_column($response['body']['memberships'], null, '$id');
        $other = $memberships[$otherUid];
        $this->assertEmpty($other['userName']);
        $this->assertEmpty($other['userEmail']);
        $this->assertFalse($other['mfa']);

        // Assert that the member still sees their own details
        unset($memberships[$otherUid]);
        $own = reset($memberships);
        $this->assertNotEmpty($own['userId']);
        $this->assertNotEmpty($own['userName']);
        $this->assertNotEmpty($own['userEmail']);

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
        $other = array_column($response['body']['memberships'], null, '$id')[$otherUid];
        $this->assertNotEmpty($other['userName']);
        $this->assertNotEmpty($other['userEmail']);
        $this->assertArrayHasKey('mfa', $other);

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
        $other = array_column($response['body']['memberships'], null, '$id')[$otherUid];
        $this->assertEmpty($other['userName']);
        $this->assertEmpty($other['userEmail']);
        $this->assertArrayHasKey('mfa', $other);
    }

    public function testTeamsInviteHTMLInjection(): void
    {
        $teamData = $this->createTeamHelper();
        $teamUid = $teamData['teamUid'];
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

        $lastEmail = $this->getLastEmailByAddress($email);
        $this->assertNotEmpty($lastEmail, 'Email not found for address: ' . $email);


        // injection allowed, meant to be protected client-side
        $encoded = 'http://localhost:5000/join-us\"></a><h1>INJECTED</h1>';

        $this->assertStringContainsString('<h1>INJECTED</h1>', (string) $lastEmail['html']);
        $this->assertStringContainsString($encoded, (string) $lastEmail['html']);

        $response = $this->client->call(Client::METHOD_DELETE, '/teams/' . $teamUid . '/memberships/'.$response['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        $this->assertEquals(204, $response['headers']['status-code']);
    }

    public function testCreateTeamMembershipConfirmsPendingInvite(): void
    {
        $teamData = $this->createTeamHelper();
        $teamUid = $teamData['teamUid'];
        $projectId = $this->getProject()['$id'];

        $membershipData = $this->createPendingMembershipHelper($teamUid, $teamData['teamName']);

        $team = $this->client->call(Client::METHOD_GET, '/teams/' . $teamUid, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()));

        $this->assertEquals(200, $team['headers']['status-code']);
        $this->assertEquals(1, $team['body']['total']); // A pending invite is not a member yet

        /**
         * Test for SUCCESS
         * A server side invite confirms the pending membership, so it has to be counted
         */
        $response = $this->client->call(Client::METHOD_POST, '/teams/' . $teamUid . '/memberships', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'userId' => $membershipData['userUid'],
            'roles' => ['developer'],
            'url' => 'http://localhost:5000/join-us#title'
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEquals($membershipData['membershipUid'], $response['body']['$id']);
        $this->assertTrue($response['body']['confirm']);

        $team = $this->client->call(Client::METHOD_GET, '/teams/' . $teamUid, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()));

        $this->assertEquals(200, $team['headers']['status-code']);
        $this->assertEquals(2, $team['body']['total']);

        $memberships = $this->client->call(Client::METHOD_GET, '/teams/' . $teamUid . '/memberships', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()));

        $this->assertEquals(200, $memberships['headers']['status-code']);
        $this->assertEquals($team['body']['total'], $memberships['body']['total']);

        /**
         * Test for FAILURE
         * Re-inviting a confirmed member must not count them twice
         */
        $response = $this->client->call(Client::METHOD_POST, '/teams/' . $teamUid . '/memberships', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'userId' => $membershipData['userUid'],
            'roles' => ['developer'],
            'url' => 'http://localhost:5000/join-us#title'
        ]);

        $this->assertEquals(409, $response['headers']['status-code']);

        $team = $this->client->call(Client::METHOD_GET, '/teams/' . $teamUid, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()));

        $this->assertEquals(200, $team['headers']['status-code']);
        $this->assertEquals(2, $team['body']['total']);
    }
}
