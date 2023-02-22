<?php

namespace Tests\E2E\Services\GraphQL;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;
use Utopia\Database\Helpers\ID;

class AccountTest extends Scope
{
    use ProjectCustom;
    use SideClient;
    use Base;

    public function testCreateAccount(): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$CREATE_ACCOUNT);
        $email = 'test' . \rand() . '@test.com';
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'userId' => ID::unique(),
                'name' => 'User Name',
                'email' => $email,
                'password' => 'password',
            ],
        ];
        $account = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $graphQLPayload);

        $this->assertIsArray($account['body']['data']);
        $this->assertArrayNotHasKey('errors', $account['body']);
        $account = $account['body']['data']['accountCreate'];
        $this->assertEquals('User Name', $account['name']);
        $this->assertEquals($email, $account['email']);

        return $account;
    }

    public function testCreateAccountSession()
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$CREATE_ACCOUNT_SESSION);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'email' => $this->getUser()['email'],
                'password' => 'password',
            ]
        ];

        $session = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $graphQLPayload);

        $this->assertArrayNotHasKey('errors', $session['body']);
        $this->assertIsArray($session['body']['data']);
        $this->assertIsArray($session['body']['data']['accountCreateEmailSession']);

        $cookie = $this->client->parseCookie((string)$session['headers']['set-cookie'])['a_session_' . $this->getProject()['$id']];
        $this->assertNotEmpty($cookie);
    }

    public function testCreateMagicURLSession(): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$CREATE_MAGIC_URL);
        $email = 'test' . \rand() . '@test.com';
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'userId' => ID::unique(),
                'email' => $email,
            ]
        ];

        $session = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $graphQLPayload);

        $this->assertArrayNotHasKey('errors', $session['body']);
        $this->assertIsArray($session['body']['data']);
        $this->assertIsArray($session['body']['data']['accountCreateMagicURLSession']);

        return $session['body']['data']['accountCreateMagicURLSession'];
    }

    public function testCreateEmailVerification(): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$CREATE_EMAIL_VERIFICATION);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'url' => 'http://localhost/verification'
            ],
        ];

        $token = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertArrayNotHasKey('errors', $token['body']);
        $this->assertIsArray($token['body']['data']);
        $this->assertIsArray($token['body']['data']['accountCreateVerification']);

        return $token['body']['data']['accountCreateVerification'];
    }

    /**
     * @depends testUpdateAccountPhone
     * @return array
     * @throws \Exception
     */
    public function testCreatePhoneVerification(): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$CREATE_PHONE_VERIFICATION);
        $graphQLPayload = [
            'query' => $query,
        ];

        $token = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertArrayNotHasKey('errors', $token['body']);
        $this->assertIsArray($token['body']['data']);
        $this->assertIsArray($token['body']['data']['accountCreatePhoneVerification']);

        return $token['body']['data']['accountCreatePhoneVerification'];
    }

    public function testCreatePasswordRecovery(): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$CREATE_PASSWORD_RECOVERY);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'email' => $this->getUser()['email'],
                'url' => 'http://localhost/recovery',
            ],
        ];

        $token = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertArrayNotHasKey('errors', $token['body']);
        $this->assertIsArray($token['body']['data']);
        $this->assertIsArray($token['body']['data']['accountCreateRecovery']);

        return $token['body']['data']['accountCreateRecovery'];
    }

    public function testCreateAnonymousSession(): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$CREATE_ANONYMOUS_SESSION);
        $graphQLPayload = [
            'query' => $query,
        ];

        $session = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $graphQLPayload);

        $this->assertArrayNotHasKey('errors', $session['body']);
        $this->assertIsArray($session['body']['data']);
        $this->assertIsArray($session['body']['data']['accountCreateAnonymousSession']);

        return $session['body']['data']['accountCreateAnonymousSession'];
    }

    public function testCreateAccountJWT(): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$CREATE_ACCOUNT_JWT);
        $graphQLPayload = [
            'query' => $query,
        ];
        $jwt = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertIsArray($jwt['body']['data']);
        $this->assertArrayNotHasKey('errors', $jwt['body']);
        $this->assertNotEmpty($jwt['body']['data']['accountCreateJWT']['jwt']);

        return $jwt;
    }

    public function testGetAccount(): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$GET_ACCOUNT);
        $graphQLPayload = [
            'query' => $query,
        ];

        $account = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertArrayNotHasKey('errors', $account['body']);
        $this->assertIsArray($account['body']['data']);

        $account = $account['body']['data']['accountGet'];
        $this->assertEquals('User Name', $account['name']);
        $this->assertEquals($this->getUser()['email'], $account['email']);

        return $account;
    }

    public function testGetAccountPreferences(): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$GET_ACCOUNT_PREFS);
        $graphQLPayload = [
            'query' => $query,
        ];

        $prefs = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertArrayNotHasKey('errors', $prefs['body']);
        $this->assertIsArray($prefs['body']['data']);
        $this->assertEquals('{}', $prefs['body']['data']['accountGetPrefs']['data']);

        return $prefs;
    }

    public function testGetAccountSessions(): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$GET_ACCOUNT_SESSIONS);
        $graphQLPayload = [
            'query' => $query,
        ];

        $sessions = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertArrayNotHasKey('errors', $sessions['body']);
        $this->assertIsArray($sessions['body']['data']);
        $this->assertIsArray($sessions['body']['data']['accountListSessions']);

        return $sessions;
    }

    public function testGetAccountSession(): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$GET_ACCOUNT_SESSION);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'sessionId' => 'current',
            ]
        ];

        $session = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertArrayNotHasKey('errors', $session['body']);
        $this->assertIsArray($session['body']['data']);
        $this->assertIsArray($session['body']['data']['accountGetSession']);
        $this->assertEquals($this->getUser()['sessionId'], $session['body']['data']['accountGetSession']['_id']);

        return $session;
    }

    public function testGetAccountLogs(): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$GET_ACCOUNT_LOGS);
        $graphQLPayload = [
            'query' => $query,
        ];

        $logs = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertArrayNotHasKey('errors', $logs['body']);
        $this->assertIsArray($logs['body']['data']);
        $this->assertIsArray($logs['body']['data']['accountListLogs']);

        return $logs;
    }

    public function testUpdateAccountName(): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$UPDATE_ACCOUNT_NAME);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'name' => 'Tester Updated'
            ]
        ];

        $account = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertArrayNotHasKey('errors', $account['body']);
        $this->assertIsArray($account['body']['data']);
        $this->assertIsArray($account['body']['data']['accountUpdateName']);
        $this->assertEquals('Tester Updated', $account['body']['data']['accountUpdateName']['name']);

        return $account;
    }

    public function testUpdateAccountEmail(): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$UPDATE_ACCOUNT_EMAIL);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'email' => 'newemail@appwrite.io',
                'password' => 'password',
            ]
        ];

        $account = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertArrayNotHasKey('errors', $account['body']);
        $this->assertIsArray($account['body']['data']);
        $this->assertIsArray($account['body']['data']['accountUpdateEmail']);
        $this->assertEquals('newemail@appwrite.io', $account['body']['data']['accountUpdateEmail']['email']);

        return $account;
    }

    public function testUpdateAccountPassword(): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$UPDATE_ACCOUNT_PASSWORD);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'oldPassword' => 'password',
                'password' => 'password',
            ]
        ];

        $account = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertArrayNotHasKey('errors', $account['body']);
        $this->assertIsArray($account['body']['data']);
        $this->assertIsArray($account['body']['data']['accountUpdatePassword']);

        return $account;
    }

    public function testUpdateAccountPhone(): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$UPDATE_ACCOUNT_PHONE);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'phone' => '+123456789',
                'password' => 'password',
            ]
        ];

        $account = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertArrayNotHasKey('errors', $account['body']);
        $this->assertIsArray($account['body']['data']);
        $this->assertIsArray($account['body']['data']['accountUpdatePhone']);
        $this->assertEquals('+123456789', $account['body']['data']['accountUpdatePhone']['phone']);

        return $account;
    }

    public function testUpdateAccountStatus(): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$UPDATE_ACCOUNT_STATUS);
        $graphQLPayload = [
            'query' => $query,
        ];

        $account = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertArrayNotHasKey('errors', $account['body']);
        $this->assertIsArray($account['body']['data']);
        $this->assertIsArray($account['body']['data']['accountUpdateStatus']);
        $this->assertEquals(false, $account['body']['data']['accountUpdateStatus']['status']);

        unset(self::$user[$this->getProject()['$id']]);
        $this->getUser();

        return $account;
    }

    public function testUpdateAccountPrefs(): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$UPDATE_ACCOUNT_PREFS);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'prefs' => [
                    'key' => 'value'
                ]
            ]
        ];

        $account = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertArrayNotHasKey('errors', $account['body']);
        $this->assertIsArray($account['body']['data']);
        $this->assertIsArray($account['body']['data']['accountUpdatePrefs']);
        $this->assertEquals(['data' => \json_encode(['key' => 'value'])], $account['body']['data']['accountUpdatePrefs']['prefs']);

        return $account;
    }

    public function testDeleteAccountSessions(): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$DELETE_ACCOUNT_SESSIONS);
        $graphQLPayload = [
            'query' => $query
        ];

        $account = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertIsNotArray($account['body']);
        $this->assertEquals(204, $account['headers']['status-code']);

        unset(self::$user[$this->getProject()['$id']]);
        $this->getUser();

        return $account;
    }

    public function testDeleteAccountSession(): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$DELETE_ACCOUNT_SESSION);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'sessionId' => 'current',
            ]
        ];

        $account = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertIsNotArray($account['body']);
        $this->assertEquals(204, $account['headers']['status-code']);

        unset(self::$user[$this->getProject()['$id']]);
        $this->getUser();

        return $account;
    }
}
