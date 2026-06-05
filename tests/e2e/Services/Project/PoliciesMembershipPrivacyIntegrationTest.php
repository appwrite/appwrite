<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Project;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Helpers\ID;

final class PoliciesMembershipPrivacyIntegrationTest extends Scope
{
    use ProjectCustom;
    use SideServer;

    public function testMembershipPrivacyIntegration(): void
    {
        $projectId = $this->getProject()['$id'];
        $apiKey = $this->getProject()['apiKey'];

        $serverHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apiKey,
            'x-appwrite-response-format' => '1.9.4',
        ];

        // Step 1: Configure privacy to false
        $response = $this->client->call(Client::METHOD_PATCH, '/project/policies/membership-privacy', $serverHeaders, [
            'userId' => false,
            'userEmail' => false,
            'userPhone' => false,
            'userName' => false,
            'userMFA' => false,
            'userAccessedAt' => false,
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertFalse($response['body']['userId']);
        $this->assertFalse($response['body']['userEmail']);
        $this->assertFalse($response['body']['userPhone']);
        $this->assertFalse($response['body']['userName']);
        $this->assertFalse($response['body']['userMFA']);
        $this->assertFalse($response['body']['userAccessedAt']);

        // Step 2: Setup two users
        $user1Email = 'user1_' . uniqid() . '@localhost.test';
        $user1Name = 'Alice Anderson';
        $user1Phone = '+12025550101';
        $password = 'password1234';

        $user1 = $this->client->call(Client::METHOD_POST, '/users', $serverHeaders, [
            'userId' => ID::unique(),
            'email' => $user1Email,
            'password' => $password,
            'name' => $user1Name,
        ]);
        $this->assertSame(201, $user1['headers']['status-code']);
        $user1Id = $user1['body']['$id'];

        $response = $this->client->call(Client::METHOD_PATCH, '/users/' . $user1Id . '/phone', $serverHeaders, [
            'number' => $user1Phone,
        ]);
        $this->assertSame(200, $response['headers']['status-code']);

        $user2Email = 'user2_' . uniqid() . '@localhost.test';
        $user2Name = 'Bob Baker';
        $user2Phone = '+12025550102';

        $user2 = $this->client->call(Client::METHOD_POST, '/users', $serverHeaders, [
            'userId' => ID::unique(),
            'email' => $user2Email,
            'password' => $password,
            'name' => $user2Name,
        ]);
        $this->assertSame(201, $user2['headers']['status-code']);
        $user2Id = $user2['body']['$id'];

        $response = $this->client->call(Client::METHOD_PATCH, '/users/' . $user2Id . '/phone', $serverHeaders, [
            'number' => $user2Phone,
        ]);
        $this->assertSame(200, $response['headers']['status-code']);

        // Step 3: Create team and add both users as members
        $team = $this->client->call(Client::METHOD_POST, '/teams', $serverHeaders, [
            'teamId' => ID::unique(),
            'name' => 'Privacy Team',
            'roles' => ['member'],
        ]);
        $this->assertSame(201, $team['headers']['status-code']);
        $teamId = $team['body']['$id'];

        $membership1 = $this->client->call(Client::METHOD_POST, '/teams/' . $teamId . '/memberships', $serverHeaders, [
            'userId' => $user1Id,
            'roles' => ['member'],
        ]);
        $this->assertSame(201, $membership1['headers']['status-code']);
        $this->assertTrue($membership1['body']['confirm']);

        $membership2 = $this->client->call(Client::METHOD_POST, '/teams/' . $teamId . '/memberships', $serverHeaders, [
            'userId' => $user2Id,
            'roles' => ['member'],
        ]);
        $this->assertSame(201, $membership2['headers']['status-code']);
        $this->assertTrue($membership2['body']['confirm']);

        // Step 4: Sign in as user1 and list memberships with privacy disabled
        $session = $this->client->call(Client::METHOD_POST, '/account/sessions/email', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], [
            'email' => $user1Email,
            'password' => $password,
        ]);
        $this->assertSame(201, $session['headers']['status-code']);
        $user1Session = $session['cookies']['a_session_' . $projectId];

        $clientHeaders = [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_' . $projectId . '=' . $user1Session,
        ];

        $response = $this->client->call(Client::METHOD_GET, '/teams/' . $teamId . '/memberships', $clientHeaders);
        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(2, $response['body']['total']);
        $this->assertCount(2, $response['body']['memberships']);

        foreach ($response['body']['memberships'] as $membership) {
            $this->assertSame('', $membership['userName']);
            $this->assertSame('', $membership['userEmail']);
            $this->assertSame('', $membership['userPhone']);
            $this->assertSame('', $membership['userId']);
            $this->assertFalse($membership['mfa']);
            $this->assertSame('', $membership['userAccessedAt']);
        }

        // Step 5: Update privacy to true
        $response = $this->client->call(Client::METHOD_PATCH, '/project/policies/membership-privacy', $serverHeaders, [
            'userId' => true,
            'userEmail' => true,
            'userPhone' => true,
            'userName' => true,
            'userMFA' => true,
            'userAccessedAt' => true,
        ]);
        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertTrue($response['body']['userId']);
        $this->assertTrue($response['body']['userEmail']);
        $this->assertTrue($response['body']['userPhone']);
        $this->assertTrue($response['body']['userName']);
        $this->assertTrue($response['body']['userMFA']);
        $this->assertTrue($response['body']['userAccessedAt']);

        // Step 6: List memberships with privacy enabled - user details exposed
        $response = $this->client->call(Client::METHOD_GET, '/teams/' . $teamId . '/memberships', $clientHeaders);
        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(2, $response['body']['total']);
        $this->assertCount(2, $response['body']['memberships']);

        $membershipsByUser = [];
        foreach ($response['body']['memberships'] as $membership) {
            $membershipsByUser[$membership['userId']] = $membership;
        }

        $this->assertArrayHasKey($user1Id, $membershipsByUser);
        $this->assertSame($user1Id, $membershipsByUser[$user1Id]['userId']);
        $this->assertSame($user1Name, $membershipsByUser[$user1Id]['userName']);
        $this->assertSame($user1Email, $membershipsByUser[$user1Id]['userEmail']);
        $this->assertSame($user1Phone, $membershipsByUser[$user1Id]['userPhone']);
        $this->assertFalse($membershipsByUser[$user1Id]['mfa']);
        $this->assertNotEmpty($membershipsByUser[$user1Id]['userAccessedAt']);

        $this->assertArrayHasKey($user2Id, $membershipsByUser);
        $this->assertSame($user2Id, $membershipsByUser[$user2Id]['userId']);
        $this->assertSame($user2Name, $membershipsByUser[$user2Id]['userName']);
        $this->assertSame($user2Email, $membershipsByUser[$user2Id]['userEmail']);
        $this->assertSame($user2Phone, $membershipsByUser[$user2Id]['userPhone']);
        $this->assertFalse($membershipsByUser[$user2Id]['mfa']);
        $this->assertNotEmpty($membershipsByUser[$user2Id]['userAccessedAt']);
    }
}
