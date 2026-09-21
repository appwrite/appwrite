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
        $this->assertFalse($response['body']['authMembershipsUserId']);
        $this->assertFalse($response['body']['authMembershipsUserEmail']);
        $this->assertFalse($response['body']['authMembershipsUserPhone']);
        $this->assertFalse($response['body']['authMembershipsUserName']);
        $this->assertFalse($response['body']['authMembershipsMfa']);
        $this->assertFalse($response['body']['authMembershipsUserAccessedAt']);

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

        // Also sign in as user2 and make a request so accessedAt is populated before privacy is enabled
        $session2 = $this->client->call(Client::METHOD_POST, '/account/sessions/email', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], [
            'email' => $user2Email,
            'password' => $password,
        ]);
        $this->assertSame(201, $session2['headers']['status-code']);
        $user2Session = $session2['cookies']['a_session_' . $projectId];

        $client2Headers = [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_' . $projectId . '=' . $user2Session,
        ];

        // Make a request as each user to ensure accessedAt is updated
        $this->client->call(Client::METHOD_GET, '/account', $client2Headers);
        $this->client->call(Client::METHOD_GET, '/account', $clientHeaders);

        $response = $this->client->call(Client::METHOD_GET, '/teams/' . $teamId . '/memberships', $clientHeaders);
        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(2, $response['body']['total']);
        $this->assertCount(2, $response['body']['memberships']);

        // The policy hides user2 from user1
        $other = $this->findMembership($response['body']['memberships'], $membership2['body']['$id']);
        $this->assertSame('', $other['userName']);
        $this->assertSame('', $other['userEmail']);
        $this->assertSame('', $other['userPhone']);
        $this->assertSame('', $other['userId']);
        $this->assertFalse($other['mfa']);
        $this->assertSame('', $other['userAccessedAt']);

        // The policy never hides user1 from themselves
        $own = $this->findMembership($response['body']['memberships'], $membership1['body']['$id']);
        $this->assertSame($user1Name, $own['userName']);
        $this->assertSame($user1Email, $own['userEmail']);
        $this->assertSame($user1Phone, $own['userPhone']);
        $this->assertSame($user1Id, $own['userId']);
        $this->assertFalse($own['mfa']);
        $this->assertNotEmpty($own['userAccessedAt']);

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
        $this->assertTrue($response['body']['authMembershipsUserId']);
        $this->assertTrue($response['body']['authMembershipsUserEmail']);
        $this->assertTrue($response['body']['authMembershipsUserPhone']);
        $this->assertTrue($response['body']['authMembershipsUserName']);
        $this->assertTrue($response['body']['authMembershipsMfa']);
        $this->assertTrue($response['body']['authMembershipsUserAccessedAt']);

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

    public function testMembershipUserAccessedAtDefault(): void
    {
        // A project that never set the policy hides the last access time
        $project = $this->getProject(true);
        $projectId = $project['$id'];

        $serverHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $project['apiKey'],
        ];

        $policy = $this->client->call(Client::METHOD_GET, '/project/policies/membership-privacy', $serverHeaders);
        $this->assertSame(200, $policy['headers']['status-code']);
        $this->assertFalse($policy['body']['userAccessedAt']);

        $member = $this->createTeamWithMember($projectId, $serverHeaders);

        $response = $this->client->call(Client::METHOD_GET, '/teams/' . $member['teamId'] . '/memberships', $member['clientHeaders']);
        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertCount(2, $response['body']['memberships']);

        $this->assertSame('', $this->findMembership($response['body']['memberships'], $member['otherMembershipId'])['userAccessedAt']);
        // A member always sees their own last access time
        $this->assertNotEmpty($this->findMembership($response['body']['memberships'], $member['membershipId'])['userAccessedAt']);

        $response = $this->client->call(Client::METHOD_GET, '/teams/' . $member['teamId'] . '/memberships/' . $member['otherMembershipId'], $member['clientHeaders']);
        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('', $response['body']['userAccessedAt']);

        $response = $this->client->call(Client::METHOD_GET, '/teams/' . $member['teamId'] . '/memberships/' . $member['membershipId'], $member['clientHeaders']);
        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['userAccessedAt']);

        // The policy only applies to client requests, an API key still sees it
        $response = $this->client->call(Client::METHOD_GET, '/teams/' . $member['teamId'] . '/memberships/' . $member['otherMembershipId'], $serverHeaders);
        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['userAccessedAt']);
    }

    public function testMembershipUserAccessedAtPolicy(): void
    {
        $projectId = $this->getProject()['$id'];

        $serverHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey'],
            'x-appwrite-response-format' => '1.9.4',
        ];

        $setAccessedAt = function (bool $visible) use ($serverHeaders): void {
            $response = $this->client->call(Client::METHOD_PATCH, '/project/policies/membership-privacy', $serverHeaders, [
                'userAccessedAt' => $visible,
            ]);
            $this->assertSame(200, $response['headers']['status-code']);
            $this->assertSame($visible, $response['body']['authMembershipsUserAccessedAt']);
        };

        $readPolicy = function () use ($serverHeaders): array {
            $get = $this->client->call(Client::METHOD_GET, '/project/policies/membership-privacy', $serverHeaders);
            $this->assertSame(200, $get['headers']['status-code']);

            $list = $this->client->call(Client::METHOD_GET, '/project/policies', $serverHeaders);
            $this->assertSame(200, $list['headers']['status-code']);

            $byId = [];
            foreach ($list['body']['policies'] as $policy) {
                $byId[$policy['$id']] = $policy;
            }
            $this->assertArrayHasKey('membership-privacy', $byId);

            // Both endpoints report the same policy
            $this->assertSame($get['body'], $byId['membership-privacy']);

            return $get['body'];
        };

        // Only userAccessedAt is toggled below, the rest must survive untouched
        $response = $this->client->call(Client::METHOD_PATCH, '/project/policies/membership-privacy', $serverHeaders, [
            'userId' => true,
            'userEmail' => true,
            'userPhone' => true,
            'userName' => true,
            'userMFA' => true,
            'userAccessedAt' => false,
        ]);
        $this->assertSame(200, $response['headers']['status-code']);

        $member = $this->createTeamWithMember($projectId, $serverHeaders);

        $policy = $readPolicy();
        $this->assertFalse($policy['userAccessedAt']);

        $response = $this->client->call(Client::METHOD_GET, '/teams/' . $member['teamId'] . '/memberships/' . $member['otherMembershipId'], $member['clientHeaders']);
        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('', $response['body']['userAccessedAt']);

        $setAccessedAt(true);

        $policy = $readPolicy();
        $this->assertTrue($policy['userAccessedAt']);
        $this->assertTrue($policy['userId']);
        $this->assertTrue($policy['userEmail']);
        $this->assertTrue($policy['userPhone']);
        $this->assertTrue($policy['userName']);
        $this->assertTrue($policy['userMFA']);

        $response = $this->client->call(Client::METHOD_GET, '/teams/' . $member['teamId'] . '/memberships/' . $member['otherMembershipId'], $member['clientHeaders']);
        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['userAccessedAt']);
        $this->assertNotFalse(\strtotime($response['body']['userAccessedAt']));

        $response = $this->client->call(Client::METHOD_GET, '/teams/' . $member['teamId'] . '/memberships', $member['clientHeaders']);
        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertCount(2, $response['body']['memberships']);
        $this->assertNotEmpty($this->findMembership($response['body']['memberships'], $member['otherMembershipId'])['userAccessedAt']);

        $setAccessedAt(false);

        $this->assertFalse($readPolicy()['userAccessedAt']);

        $response = $this->client->call(Client::METHOD_GET, '/teams/' . $member['teamId'] . '/memberships', $member['clientHeaders']);
        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('', $this->findMembership($response['body']['memberships'], $member['otherMembershipId'])['userAccessedAt']);
    }

    /**
     * @param  array<int, array<string, mixed>>  $memberships
     * @return array<string, mixed>
     */
    private function findMembership(array $memberships, string $membershipId): array
    {
        $membershipsById = array_column($memberships, null, '$id');
        $this->assertArrayHasKey($membershipId, $membershipsById);

        return $membershipsById[$membershipId];
    }

    /**
     * Create a team with a signed-in viewer and one other member, both with accessedAt populated.
     *
     * @param  array<string, string>  $serverHeaders
     * @return array{teamId: string, membershipId: string, otherMembershipId: string, clientHeaders: array<string, string>}
     */
    private function createTeamWithMember(string $projectId, array $serverHeaders): array
    {
        $team = $this->client->call(Client::METHOD_POST, '/teams', $serverHeaders, [
            'teamId' => ID::unique(),
            'name' => 'Access Team',
            'roles' => ['member'],
        ]);
        $this->assertSame(201, $team['headers']['status-code']);
        $teamId = $team['body']['$id'];

        $viewer = $this->addTeamMember($projectId, $serverHeaders, $teamId, 'Casey Carter');
        $other = $this->addTeamMember($projectId, $serverHeaders, $teamId, 'Dana Dean');

        return [
            'teamId' => $teamId,
            'membershipId' => $viewer['membershipId'],
            'otherMembershipId' => $other['membershipId'],
            'clientHeaders' => $viewer['clientHeaders'],
        ];
    }

    /**
     * Create a user, add them to the team and sign them in so their accessedAt is populated.
     *
     * @param  array<string, string>  $serverHeaders
     * @return array{membershipId: string, clientHeaders: array<string, string>}
     */
    private function addTeamMember(string $projectId, array $serverHeaders, string $teamId, string $name): array
    {
        $email = 'member_' . uniqid() . '@localhost.test';
        $password = 'password1234';

        $user = $this->client->call(Client::METHOD_POST, '/users', $serverHeaders, [
            'userId' => ID::unique(),
            'email' => $email,
            'password' => $password,
            'name' => $name,
        ]);
        $this->assertSame(201, $user['headers']['status-code']);

        $membership = $this->client->call(Client::METHOD_POST, '/teams/' . $teamId . '/memberships', $serverHeaders, [
            'userId' => $user['body']['$id'],
            'roles' => ['member'],
        ]);
        $this->assertSame(201, $membership['headers']['status-code']);

        $session = $this->client->call(Client::METHOD_POST, '/account/sessions/email', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], [
            'email' => $email,
            'password' => $password,
        ]);
        $this->assertSame(201, $session['headers']['status-code']);

        $clientHeaders = [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_' . $projectId . '=' . $session['cookies']['a_session_' . $projectId],
        ];

        // Populate accessedAt
        $response = $this->client->call(Client::METHOD_GET, '/account', $clientHeaders);
        $this->assertSame(200, $response['headers']['status-code']);

        return [
            'membershipId' => $membership['body']['$id'],
            'clientHeaders' => $clientHeaders,
        ];
    }
}
