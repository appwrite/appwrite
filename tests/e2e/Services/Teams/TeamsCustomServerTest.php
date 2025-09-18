<?php

namespace Tests\E2E\Services\Teams;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Validator\Datetime as DatetimeValidator;

class TeamsCustomServerTest extends Scope
{
    use TeamsBase;
    use TeamsBaseServer;
    use ProjectCustom;
    use SideServer;

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
