<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Teams;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectConsole;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;
use Utopia\Database\Helpers\ID;
use Utopia\System\System;

final class TeamsConsoleClientTest extends Scope
{
    use TeamsBase;
    use TeamsBaseClient;
    use ProjectConsole;
    use SideClient;

    public function testCreateOrganization(): void
    {
        if (System::getEnv('_APP_E2E_FRESH_INSTANCE', 'disabled') !== 'enabled') {
            $this->markTestSkipped('Requires an isolated fresh instance with console signup enabled.');
        }

        $accounts = [$this->getUser(true), $this->getUser(true)];
        $headers = ['content-type' => 'application/json', 'x-appwrite-project' => 'console'];
        $handles = [];
        $multi = curl_multi_init();
        $owner = null;
        $organization = null;

        /**
         * Test for SUCCESS: same-account and cross-account races create only one organization.
         */
        try {
            foreach ([0, 1, 0, 1] as $index) {
                $handle = curl_init($this->endpoint . '/teams');
                curl_setopt_array($handle, [
                    CURLOPT_POST => true,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/json',
                        'X-Appwrite-Project: console',
                        'Cookie: a_session_console=' . $accounts[$index]['session'],
                    ],
                    CURLOPT_POSTFIELDS => json_encode(['teamId' => ID::unique(), 'name' => 'Instance organization']),
                ]);
                curl_multi_add_handle($multi, $handle);
                $handles[] = [$handle, $index];
            }
            do {
                $this->assertSame(CURLM_OK, curl_multi_exec($multi, $running));
                if ($running) {
                    curl_multi_select($multi, 0.1);
                }
            } while ($running);

            $created = 0;
            foreach ($handles as [$handle, $index]) {
                $this->assertSame(0, curl_errno($handle), curl_error($handle));
                $code = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
                $body = json_decode(curl_multi_getcontent($handle), true);
                if ($code === 201) {
                    $created++;
                    $owner = $accounts[$index];
                    $organization = $body;
                } else {
                    $this->assertContains($code, [403, 409], json_encode($body));
                    $this->assertSame($code === 403 ? 'organization_creation_prohibited' : 'general_resource_locked', $body['type']);
                }
            }
            $this->assertSame(1, $created);
        } finally {
            foreach ($handles as [$handle]) {
                curl_multi_remove_handle($multi, $handle);
            }
            curl_multi_close($multi);
        }

        $ownerHeaders = [...$headers, 'cookie' => 'a_session_console=' . $owner['session']];
        $response = $this->client->call(Client::METHOD_GET, '/teams', $ownerHeaders);
        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(1, $response['body']['total']);
        $this->assertSame($organization['$id'], $response['body']['teams'][0]['$id']);

        /**
         * Test for FAILURE: neither the owner nor a newly registered account can create another.
         */
        $outsiderHeaders = [...$headers, 'cookie' => 'a_session_console=' . $this->getUser(true)['session']];
        $response = $this->client->call(Client::METHOD_GET, '/teams', $outsiderHeaders);
        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(0, $response['body']['total']);

        foreach ([$ownerHeaders, $outsiderHeaders] as $requestHeaders) {
            $response = $this->client->call(Client::METHOD_POST, '/teams', $requestHeaders, [
                'teamId' => ID::unique(),
                'name' => 'Another organization',
            ]);
            $this->assertSame(403, $response['headers']['status-code']);
            $this->assertSame('organization_creation_prohibited', $response['body']['type']);
        }
        $response = $this->client->call(Client::METHOD_GET, '/teams', $ownerHeaders);
        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(1, $response['body']['total']);
    }

    public function testConsoleMembershipPrivacyDefaults(): void
    {
        $teamData = $this->createTeamHelper();
        $membershipData = $this->createAndAcceptMembershipHelper($teamData['teamUid'], $teamData['teamName']);

        $teamUid = $teamData['teamUid'];
        $projectId = $this->getProject()['$id'];
        $owner = $this->getUser();
        $memberHeaders = [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_' . $projectId . '=' . $membershipData['session'],
        ];

        $ownerMemberships = $this->client->call(Client::METHOD_GET, '/teams/' . $teamUid . '/memberships', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()));

        $this->assertEquals(200, $ownerMemberships['headers']['status-code']);
        $this->assertEquals(2, $ownerMemberships['body']['total']);

        $ownerMembershipsByUser = [];
        foreach ($ownerMemberships['body']['memberships'] as $membership) {
            $ownerMembershipsByUser[$membership['userId']] = $membership;
        }

        $this->assertArrayHasKey($owner['$id'], $ownerMembershipsByUser);
        $this->assertContains('owner', $ownerMembershipsByUser[$owner['$id']]['roles']);

        $this->assertArrayHasKey($membershipData['userUid'], $ownerMembershipsByUser);
        $this->assertNotContains('owner', $ownerMembershipsByUser[$membershipData['userUid']]['roles']);
        $this->assertSame($membershipData['userUid'], $ownerMembershipsByUser[$membershipData['userUid']]['userId']);
        $this->assertSame($membershipData['name'], $ownerMembershipsByUser[$membershipData['userUid']]['userName']);
        $this->assertSame($membershipData['email'], $ownerMembershipsByUser[$membershipData['userUid']]['userEmail']);
        $this->assertFalse($ownerMembershipsByUser[$membershipData['userUid']]['mfa']);

        $memberMemberships = $this->client->call(Client::METHOD_GET, '/teams/' . $teamUid . '/memberships', $memberHeaders);

        $this->assertEquals(200, $memberMemberships['headers']['status-code']);
        $this->assertEquals(2, $memberMemberships['body']['total']);

        $memberMembershipsByUser = [];
        foreach ($memberMemberships['body']['memberships'] as $membership) {
            $memberMembershipsByUser[$membership['userId']] = $membership;
        }

        $this->assertArrayHasKey($owner['$id'], $memberMembershipsByUser);
        $this->assertSame($owner['$id'], $memberMembershipsByUser[$owner['$id']]['userId']);
        $this->assertSame($owner['name'], $memberMembershipsByUser[$owner['$id']]['userName']);
        $this->assertSame($owner['email'], $memberMembershipsByUser[$owner['$id']]['userEmail']);
        $this->assertFalse($memberMembershipsByUser[$owner['$id']]['mfa']);
        $this->assertContains('owner', $memberMembershipsByUser[$owner['$id']]['roles']);

        $this->assertArrayHasKey($membershipData['userUid'], $memberMembershipsByUser);
        $this->assertNotContains('owner', $memberMembershipsByUser[$membershipData['userUid']]['roles']);
    }

    public function testTeamCreateMembershipConsole(): void
    {
        $teamData = $this->createTeamHelper();
        $teamUid = $teamData['teamUid'];
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
    }

    public function testTeamMembershipPerms(): void
    {
        $teamData = $this->createTeamHelper();
        $teamUid = $teamData['teamUid'];
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
    }

    public function testUpdateTeamMembershipRoles(): void
    {
        $teamData = $this->createTeamHelper();
        $membershipData = $this->createAndAcceptMembershipHelper($teamData['teamUid'], $teamData['teamName']);

        $teamUid = $membershipData['teamUid'];
        $membershipUid = $membershipData['membershipUid'];
        $session = $membershipData['session'];

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
    }

    public function testDeleteTeamMembership(): void
    {
        $teamData = $this->createTeamHelper();
        $teamUid = $teamData['teamUid'];
        $teamName = $teamData['teamName'];

        // Create multiple memberships for the delete test
        $this->createPendingMembershipHelper($teamUid, $teamName);
        $membershipData = $this->createAndAcceptMembershipHelper($teamUid, $teamName);

        $membershipUid = $membershipData['membershipUid'];
        $session = $membershipData['session'];

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
    }
}
