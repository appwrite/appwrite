<?php

namespace Tests\E2E\Services\Teams;

use Tests\E2E\Client;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;
use Utopia\Database\Validator\Datetime as DatetimeValidator;

trait TeamsBaseServer
{
    /**
     * Helper method to create a server-side team membership (auto-confirmed).
     * Returns membership data for tests.
     *
     * @param string $teamUid Team ID
     * @param string $teamName Team name
     * @return array{teamUid: string, userUid: string, membershipUid: string}
     */
    protected function createServerMembershipHelper(string $teamUid, string $teamName): array
    {
        $email = uniqid() . 'friend@localhost.test';

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

        return [
            'teamUid' => $teamUid,
            'userUid' => $response['body']['userId'],
            'membershipUid' => $response['body']['$id'],
        ];
    }

    public function testGetTeamMemberships(): void
    {
        $teamData = $this->createTeamHelper();
        $id = $teamData['teamUid'];

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
    }

    public function testGetTeamMembership(): void
    {
        $teamData = $this->createTeamHelper();
        $membershipData = $this->createServerMembershipHelper($teamData['teamUid'], $teamData['teamName']);
        $teamUid = $membershipData['teamUid'];
        $membershipUid = $membershipData['membershipUid'];

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

    public function testCreateTeamMembership(): void
    {
        $teamData = $this->createTeamHelper();
        $teamUid = $teamData['teamUid'];
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
    }

    public function testCreateTeamMembershipConcurrentDuplicate(): void
    {
        $team = $this->client->call(Client::METHOD_POST, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'teamId' => ID::unique(),
            'name' => 'Concurrent Membership Team',
        ]);

        $this->assertSame(201, $team['headers']['status-code']);

        $userId = ID::unique();
        $user = $this->client->call(Client::METHOD_POST, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'userId' => $userId,
            'email' => uniqid() . 'parallel@localhost.test',
            'password' => 'password',
            'name' => 'Parallel User',
        ]);

        $this->assertSame(201, $user['headers']['status-code']);

        $requests = 8;
        $responses = $this->createMembershipsConcurrently($team['body']['$id'], $userId, $requests);
        $statuses = array_map(fn (array $response): int => $response['headers']['status-code'], $responses);

        foreach ($responses as $response) {
            $status = $response['headers']['status-code'];
            $body = is_string($response['body'])
                ? $response['body']
                : json_encode($response['body'], JSON_THROW_ON_ERROR);

            $this->assertNotSame(500, $status);
            $this->assertContains($status, [201, 409]);
            $this->assertStringNotContainsString('Duplicate', $body);
            $this->assertStringNotContainsString('Document with the requested unique attributes already exists', $body);

            if ($status === 409) {
                $this->assertIsArray($response['body']);
                $this->assertSame('membership_already_confirmed', $response['body']['type'] ?? null);
            }
        }

        sort($statuses);

        $this->assertSame(array_merge([201], array_fill(0, $requests - 1, 409)), $statuses);

        $memberships = $this->client->call(Client::METHOD_GET, '/teams/' . $team['body']['$id'] . '/memberships', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::equal('userId', [$userId])->toString(),
            ],
        ]);

        $this->assertSame(200, $memberships['headers']['status-code']);
        $this->assertSame(1, $memberships['body']['total']);
        $this->assertCount(1, $memberships['body']['memberships']);

        $team = $this->client->call(Client::METHOD_GET, '/teams/' . $team['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertSame(200, $team['headers']['status-code']);
        $this->assertSame(1, $team['body']['total']);
    }

    private function createMembershipsConcurrently(string $teamId, string $userId, int $requests): array
    {
        $multi = curl_multi_init();
        $handles = [];
        $headers = [];
        $responses = [];
        $payload = json_encode([
            'userId' => $userId,
            'roles' => ['admin', 'editor'],
            'url' => 'http://localhost:5000/join-us#title',
        ], JSON_THROW_ON_ERROR);
        $requestHeaders = array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders());
        $formattedHeaders = [];

        foreach ($requestHeaders as $key => $value) {
            $formattedHeaders[] = $key . ': ' . $value;
        }

        for ($index = 0; $index < $requests; $index++) {
            $handle = curl_init($this->client->getEndpoint() . '/teams/' . $teamId . '/memberships');

            curl_setopt($handle, CURLOPT_CUSTOMREQUEST, Client::METHOD_POST);
            curl_setopt($handle, CURLOPT_HEADERFUNCTION, function (\CurlHandle $handle, string $header) use (&$headers, $index): int {
                $length = strlen($header);
                $parts = explode(':', $header, 2);

                if (count($parts) === 2) {
                    $headers[$index][strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return $length;
            });
            curl_setopt($handle, CURLOPT_HTTPHEADER, $formattedHeaders);
            curl_setopt($handle, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($handle, CURLOPT_TIMEOUT, 15);

            curl_multi_add_handle($multi, $handle);
            $handles[$index] = $handle;
        }

        do {
            $status = curl_multi_exec($multi, $running);

            if ($running > 0) {
                curl_multi_select($multi);
            }
        } while ($running > 0 && $status === CURLM_OK);

        foreach ($handles as $index => $handle) {
            $body = curl_multi_getcontent($handle);
            $headers[$index]['status-code'] = curl_getinfo($handle, CURLINFO_HTTP_CODE);

            if (is_string($body) && str_contains($headers[$index]['content-type'] ?? '', 'application/json')) {
                $decoded = json_decode($body, true);

                if (json_last_error() === JSON_ERROR_NONE) {
                    $body = $decoded;
                }
            }

            $responses[$index] = [
                'headers' => $headers[$index],
                'body' => $body,
            ];

            curl_multi_remove_handle($multi, $handle);
            curl_close($handle);
        }

        curl_multi_close($multi);
        ksort($responses);

        return array_values($responses);
    }

    public function testUpdateMembershipRoles(): void
    {
        $teamData = $this->createTeamHelper();
        $membershipData = $this->createServerMembershipHelper($teamData['teamUid'], $teamData['teamName']);
        $teamUid = $membershipData['teamUid'];
        $membershipUid = $membershipData['membershipUid'];

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
    }

    public function testDeleteUserUpdatesTeamMembershipCount(): void
    {
        $teamData = $this->createTeamHelper();
        $membershipData = $this->createServerMembershipHelper($teamData['teamUid'], $teamData['teamName']);
        $teamUid = $membershipData['teamUid'];
        $userUid = $membershipData['userUid'];

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
        $this->assertEventually(function () use ($teamUid) {
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
        }, 30_000, 500);
    }
}
