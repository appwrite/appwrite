<?php

namespace Tests\E2E\Services\GraphQL;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;

class UsersTest extends Scope
{
    use ProjectCustom;
    use SideServer;
    use Base;

    public function testCreateUser(): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$CREATE_USER);
        $email = 'users.service@example.com';
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'userId' => ID::unique(),
                'email' => $email,
                'password' => 'password',
                'name' => 'Project User',
            ]
        ];

        $user = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertIsArray($user['body']['data']);
        $this->assertArrayNotHasKey('errors', $user['body']);

        $user = $user['body']['data']['usersCreate'];
        $this->assertEquals('Project User', $user['name']);
        $this->assertEquals($email, $user['email']);

        return $user;
    }

    /**
     * @depends testCreateUser
     */
    public function testCreateUserTarget(array $user)
    {
        $projectId = $this->getProject()['$id'];

        $query = $this->getQuery(self::$CREATE_MAILGUN_PROVIDER);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'providerId' => ID::unique(),
                'name' => 'Mailgun1',
                'apiKey' => 'api-key',
                'domain' => 'domain',
                'fromName' => 'sender name',
                'fromEmail' => 'from@domain.com',
                'isEuRegion' => false,
            ],
        ];
        $provider = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);
        $providerId = $provider['body']['data']['messagingCreateMailgunProvider']['_id'];

        $this->assertEquals(200, $provider['headers']['status-code']);

        $query = $this->getQuery(self::$CREATE_USER_TARGET);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'targetId' => ID::unique(),
                'userId' => $user['_id'],
                'providerType' => 'email',
                'providerId' => $providerId,
                'identifier' => 'random-email@mail.org',
            ]
        ];

        $target = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertEquals(200, $target['headers']['status-code']);
        $this->assertEquals('random-email@mail.org', $target['body']['data']['usersCreateTarget']['identifier']);

        return $target['body']['data']['usersCreateTarget'];
    }

    public function testGetUsers()
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$GET_USERS);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'queries' => [
                    Query::limit(100)->toString(),
                    Query::offset(0)->toString(),
                ],
            ]
        ];

        $users = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertIsArray($users['body']['data']);
        $this->assertArrayNotHasKey('errors', $users['body']);
        $this->assertIsArray($users['body']['data']['usersList']);
        $this->assertGreaterThan(0, \count($users['body']['data']['usersList']));
    }

    public function testGetUser()
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$GET_USER);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'userId' => $this->getUser()['$id'],
            ]
        ];

        $user = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertIsArray($user['body']['data']);
        $this->assertArrayNotHasKey('errors', $user['body']);
        $this->assertIsArray($user['body']['data']['usersGet']);
        $this->assertEquals($this->getUser()['$id'], $user['body']['data']['usersGet']['_id']);
    }

    public function testGetUserPreferences()
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$GET_USER_PREFERENCES);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'userId' => $this->getUser()['$id'],
            ]
        ];

        $user = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertIsArray($user['body']['data']);
        $this->assertArrayNotHasKey('errors', $user['body']);
        $this->assertIsArray($user['body']['data']['usersGetPrefs']);
    }

    public function testGetUserSessions()
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$GET_USER_SESSIONS);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'userId' => $this->getUser()['$id'],
            ]
        ];

        $user = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertIsArray($user['body']['data']);
        $this->assertArrayNotHasKey('errors', $user['body']);
        $this->assertIsArray($user['body']['data']['usersListSessions']);
    }

    public function testGetUserMemberships()
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$GET_USER_MEMBERSHIPS);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'userId' => $this->getUser()['$id'],
            ]
        ];

        $user = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertIsArray($user['body']['data']);
        $this->assertArrayNotHasKey('errors', $user['body']);
        $this->assertIsArray($user['body']['data']['usersListMemberships']);
    }

    public function testGetUserLogs()
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$GET_USER_LOGS);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'userId' => $this->getUser()['$id'],
            ]
        ];

        $user = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertIsArray($user['body']['data']);
        $this->assertArrayNotHasKey('errors', $user['body']);
        $this->assertIsArray($user['body']['data']['usersListLogs']);
    }

    /**
     * @depends testCreateUserTarget
     */
    public function testListUserTargets(array $target)
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$LIST_USER_TARGETS);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'userId' => $target['userId'],
            ]
        ];

        $targets = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertEquals(200, $targets['headers']['status-code']);
        $this->assertIsArray($targets['body']['data']['usersListTargets']);
        $this->assertCount(2, $targets['body']['data']['usersListTargets']['targets']);
    }

    /**
     * @depends testCreateUserTarget
     */
    public function testGetUserTarget(array $target)
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$GET_USER_TARGET);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'userId' => $target['userId'],
                'targetId' => $target['_id'],
            ]
        ];

        $target = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertEquals(200, $target['headers']['status-code']);
        $this->assertEquals('random-email@mail.org', $target['body']['data']['usersGetTarget']['identifier']);
    }

    public function testUpdateUserStatus()
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$UPDATE_USER_STATUS);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'userId' => $this->getUser()['$id'],
                'status' => true,
            ]
        ];

        $user = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertIsArray($user['body']['data']);
        $this->assertArrayNotHasKey('errors', $user['body']);
        $this->assertIsArray($user['body']['data']['usersUpdateStatus']);
        $this->assertEquals($this->getUser()['$id'], $user['body']['data']['usersUpdateStatus']['_id']);
    }

    public function testUpdateUserEmailVerification()
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$UPDATE_USER_EMAIL_VERIFICATION);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'userId' => $this->getUser()['$id'],
                'emailVerification' => true,
            ]
        ];

        $user = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertIsArray($user['body']['data']);
        $this->assertArrayNotHasKey('errors', $user['body']);
        $this->assertIsArray($user['body']['data']['usersUpdateEmailVerification']);
    }

    public function testUpdateUserPhoneVerification()
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$UPDATE_USER_PHONE_VERIFICATION);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'userId' => $this->getUser()['$id'],
                'phoneVerification' => true,
            ]
        ];

        $user = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertIsArray($user['body']['data']);
        $this->assertArrayNotHasKey('errors', $user['body']);
        $this->assertIsArray($user['body']['data']['usersUpdatePhoneVerification']);
        $this->assertEquals($this->getUser()['$id'], $user['body']['data']['usersUpdatePhoneVerification']['_id']);
    }

    public function testUpdateUserName()
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$UPDATE_USER_NAME);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'userId' => $this->getUser()['$id'],
                'name' => 'Updated Name',
            ]
        ];

        $user = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertIsArray($user['body']['data']);
        $this->assertArrayNotHasKey('errors', $user['body']);
        $this->assertIsArray($user['body']['data']['usersUpdateName']);
        $this->assertEquals('Updated Name', $user['body']['data']['usersUpdateName']['name']);
    }

    public function testUpdateUserEmail()
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$UPDATE_USER_EMAIL);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'userId' => $this->getUser()['$id'],
                'email' => 'newemail@appwrite.io'
            ],
        ];

        $user = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertIsArray($user['body']['data']);
        $this->assertArrayNotHasKey('errors', $user['body']);
        $this->assertIsArray($user['body']['data']['usersUpdateEmail']);
        $this->assertEquals('newemail@appwrite.io', $user['body']['data']['usersUpdateEmail']['email']);
    }

    public function testUpdateUserPassword()
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$UPDATE_USER_PASSWORD);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'userId' => $this->getUser()['$id'],
                'password' => 'newpassword'
            ],
        ];

        $user = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertIsArray($user['body']['data']);
        $this->assertArrayNotHasKey('errors', $user['body']);
        $this->assertIsArray($user['body']['data']['usersUpdatePassword']);
    }

    public function testUpdateUserPhone()
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$UPDATE_USER_PHONE);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'userId' => $this->getUser()['$id'],
                'number' => '+123456789'
            ],
        ];

        $user = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertIsArray($user['body']['data']);
        $this->assertArrayNotHasKey('errors', $user['body']);
        $this->assertIsArray($user['body']['data']['usersUpdatePhone']);
        $this->assertEquals('+123456789', $user['body']['data']['usersUpdatePhone']['phone']);
    }

    public function testUpdateUserPrefs()
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$UPDATE_USER_PREFS);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'userId' => $this->getUser()['$id'],
                'prefs' => [
                    'key' => 'value'
                ]
            ],
        ];

        $user = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertIsArray($user['body']['data']);
        $this->assertArrayNotHasKey('errors', $user['body']);
        $this->assertIsArray($user['body']['data']['usersUpdatePrefs']);
        $this->assertEquals('{"key":"value"}', $user['body']['data']['usersUpdatePrefs']['data']);
    }

    /**
     * @depends testCreateUserTarget
     */
    public function testUpdateUserTarget(array $target)
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$UPDATE_USER_TARGET);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'userId' => $target['userId'],
                'targetId' => $target['_id'],
                'identifier' => 'random-email1@mail.org',
            ],
        ];

        $target = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertEquals(200, $target['headers']['status-code']);
        $this->assertEquals('random-email1@mail.org', $target['body']['data']['usersUpdateTarget']['identifier']);
    }

    public function testDeleteUserSessions()
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$DELETE_USER_SESSIONS);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'userId' => $this->getUser()['$id'],
            ]
        ];

        $user = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertIsNotArray($user['body']);
        $this->assertEquals(204, $user['headers']['status-code']);

        unset(self::$user[$this->getProject()['$id']]);
        $this->getUser();
    }

    public function testDeleteUserSession()
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$DELETE_USER_SESSION);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'userId' => $this->getUser()['$id'],
                'sessionId' => $this->getUser()['sessionId'],
            ]
        ];

        $user = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertIsNotArray($user['body']);
        $this->assertEquals(204, $user['headers']['status-code']);

        unset(self::$user[$this->getProject()['$id']]);
        $this->getUser();
    }

    /**
     * @depends testCreateUserTarget
     */
    public function testDeleteUserTarget(array $target)
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$DELETE_USER_TARGET);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'userId' => $target['userId'],
                'targetId' => $target['_id'],
            ]
        ];

        $target = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertEquals(204, $target['headers']['status-code']);
    }

    public function testDeleteUser()
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$DELETE_USER);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'userId' => $this->getUser()['$id'],
            ]
        ];

        $user = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertIsNotArray($user['body']);
        $this->assertEquals(204, $user['headers']['status-code']);
    }
}
