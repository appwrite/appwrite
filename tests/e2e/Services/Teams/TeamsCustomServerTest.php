<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Teams;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Validator\Datetime as DatetimeValidator;

final class TeamsCustomServerTest extends Scope
{
    use TeamsBase;
    use TeamsBaseServer;
    use ProjectCustom;
    use SideServer;

    public function testCreateMembershipWithNullOptionals(): void
    {
        $headers = array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders());
        $teamId = ID::unique();
        $team = $this->client->call(Client::METHOD_POST, '/teams', $headers, [
            'teamId' => $teamId,
            'name' => 'Nullable membership fields',
        ]);
        $this->assertEquals(201, $team['headers']['status-code']);

        try {
            // Test for SUCCESS: explicit nulls behave like omitted optional fields.
            $email = ID::unique() . '@localhost.test';
            $membership = $this->client->call(Client::METHOD_POST, '/teams/' . $teamId . '/memberships', $headers, [
                'email' => $email,
                'phone' => null,
                'url' => null,
                'name' => null,
                'roles' => ['editor'],
            ]);
            $this->assertEquals(201, $membership['headers']['status-code']);
            $userId = $membership['body']['userId'];

            $this->assertEquals($email, $membership['body']['userEmail']);
            $this->assertEquals($email, $membership['body']['userName']);
            $this->assertTrue($membership['body']['confirm']);

            $membershipId = $membership['body']['$id'];
            $stored = $this->client->call(Client::METHOD_GET, '/teams/' . $teamId . '/memberships/' . $membershipId, $headers);
            $this->assertEquals(200, $stored['headers']['status-code']);
            $this->assertEquals($userId, $stored['body']['userId']);
            $this->assertEquals(['editor'], $stored['body']['roles']);

            $deleted = $this->client->call(Client::METHOD_DELETE, '/teams/' . $teamId . '/memberships/' . $membershipId, $headers);
            $this->assertEquals(204, $deleted['headers']['status-code']);

            $membership = $this->client->call(Client::METHOD_POST, '/teams/' . $teamId . '/memberships', $headers, [
                'userId' => $userId,
                'email' => null,
                'phone' => null,
                'url' => null,
                'name' => null,
                'roles' => ['reader'],
            ]);
            $this->assertEquals(201, $membership['headers']['status-code']);
            $this->assertEquals($userId, $membership['body']['userId']);
            $this->assertEquals($email, $membership['body']['userEmail']);

            // Test for FAILURE: nulls do not bypass the required identity check.
            $invalid = $this->client->call(Client::METHOD_POST, '/teams/' . $teamId . '/memberships', $headers, [
                'userId' => null,
                'email' => null,
                'phone' => null,
                'roles' => [],
            ]);
            $this->assertEquals(400, $invalid['headers']['status-code']);
            $this->assertEquals('general_argument_invalid', $invalid['body']['type']);

            $invalid = $this->client->call(Client::METHOD_POST, '/teams/' . $teamId . '/memberships', $headers, [
                'email' => $email,
                'phone' => 'not-a-phone-number',
                'roles' => [],
            ]);
            $this->assertEquals(400, $invalid['headers']['status-code']);
            $this->assertEquals('general_argument_invalid', $invalid['body']['type']);

            $memberships = $this->client->call(Client::METHOD_GET, '/teams/' . $teamId . '/memberships', $headers);
            $this->assertEquals(200, $memberships['headers']['status-code']);
            $this->assertEquals(1, $memberships['body']['total']);
            $this->assertEquals($userId, $memberships['body']['memberships'][0]['userId']);
            $this->assertEquals(['reader'], $memberships['body']['memberships'][0]['roles']);
        } finally {
            $this->client->call(Client::METHOD_DELETE, '/teams/' . $teamId, $headers);
            if (isset($userId)) {
                $this->client->call(Client::METHOD_DELETE, '/users/' . $userId, $headers);
            }
        }
    }

    public function testMembershipDeletedWhenTeamDeleted(): array
    {
        /* 1. Create Team */
        $response = $this->client->call(Client::METHOD_POST, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'teamId' => ID::unique(),
            'name' => 'Demo'
        ]);

        $teamUid = $response['body']['$id'];

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals('Demo', $response['body']['name']);
        $this->assertGreaterThan(-1, $response['body']['total']);
        $this->assertIsInt($response['body']['total']);
        $this->assertArrayHasKey('prefs', $response['body']);
        $dateValidator = new DatetimeValidator();
        $this->assertEquals(true, $dateValidator->isValid($response['body']['$createdAt']));

        /* 2. Create user. */
        $email = uniqid() . 'friend@localhost.test';
        $name = 'Friend User';
        $password = 'password';
        $userId = ID::unique();

        // Create a user account before we create a invite so we can check if the user has permissions when it shouldn't
        $user = $this->client->call(Client::METHOD_POST, '/account', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'userId' => $userId,
            'email' => $email,
            'password' => $password,
            'name' => $name,
        ], false);

        $this->assertEquals(201, $user['headers']['status-code']);

        /* 3. Add membership to user. */
        $response = $this->client->call(Client::METHOD_POST, '/teams/' . $teamUid . '/memberships', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'userId' => $userId,
            'roles' => ['admin', 'editor'],
            'url' => 'http://localhost:5000/join-us#title'
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);

        /* 4. Ensure user is a member. */
        $response = $this->client->call(Client::METHOD_GET, '/users/' . $userId . '/memberships', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals($teamUid, $response['body']['memberships'][0]['teamId']);

        /* 5. Delete Team */
        $response = $this->client->call(Client::METHOD_DELETE, '/teams/' . $teamUid, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(204, $response['headers']['status-code']);
        $this->assertEmpty($response['body']);

        /* 6. Ensure Team got deleted */
        $response = $this->client->call(Client::METHOD_GET, '/teams/' . $teamUid, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(404, $response['headers']['status-code']);

        /* 7. Ensure memberships got removed from the user. */
        $response = $this->client->call(Client::METHOD_GET, '/users/' . $userId . '/memberships', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEmpty($response['body']['memberships']);

        return [];
    }
}
