<?php

namespace Tests\E2E\Services\Account;

use Appwrite\Tests\Retry;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;
use Utopia\Database\DateTime;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;
use Utopia\Database\Validator\Datetime as DatetimeValidator;

use function sleep;

class AccountCustomClientTest extends Scope
{
    use AccountBase;
    use ProjectCustom;
    use SideClient;

    /**
     * @depends testCreateAccount
     */
    public function testCreateAccountSession($data): array
    {
        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotFalse(\DateTime::createFromFormat('Y-m-d\TH:i:s.uP', $response['body']['expire']));

        $sessionId = $response['body']['$id'];
        $session = $response['cookies']['a_session_' . $this->getProject()['$id']];

        // apiKey is only available in custom client test
        $apiKey = $this->getProject()['apiKey'];
        if (!empty($apiKey)) {
            $userId = $response['body']['userId'];
            $response = $this->client->call(Client::METHOD_GET, '/users/' . $userId, array_merge([
                'origin' => 'http://localhost',
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $apiKey,
            ]));
            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertArrayHasKey('accessedAt', $response['body']);
            $this->assertNotEmpty($response['body']['accessedAt']);
        }

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEmpty($response['body']['secret']);
        $this->assertNotFalse(\DateTime::createFromFormat('Y-m-d\TH:i:s.uP', $response['body']['expire']));

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email . 'x',
            'password' => $password,
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => $password . 'x',
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => '',
            'password' => '',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        return array_merge($data, [
            'sessionId' => $sessionId,
            'session' => $session,
        ]);
    }

    /**
     * @depends testCreateAccountSession
     */
    public function testGetAccount($data): array
    {
        $email = $data['email'] ?? '';
        $name = $data['name'] ?? '';
        $session = $data['session'] ?? '';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($response['body']['registration']));
        $this->assertEquals($response['body']['email'], $email);
        $this->assertEquals($response['body']['name'], $name);
        $this->assertArrayHasKey('accessedAt', $response['body']);
        $this->assertNotEmpty($response['body']['accessedAt']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]));

        $this->assertEquals(401, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/account', [
            'content-type' => 'application/json',
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session . 'xx',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);

        return $data;
    }

    /**
     * @depends testCreateAccountSession
     */
    public function testGetAccountPrefs($data): array
    {
        $session = $data['session'] ?? '';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/account/prefs', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertIsArray($response['body']);
        $this->assertEmpty($response['body']);
        $this->assertCount(0, $response['body']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/account/prefs', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]));

        $this->assertEquals(401, $response['headers']['status-code']);

        return $data;
    }

    /**
     * @depends testCreateAccountSession
     */
    public function testGetAccountSessions($data): array
    {
        $session = $data['session'] ?? '';
        $sessionId = $data['sessionId'] ?? '';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/account/sessions', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(2, $response['body']['total']);
        $this->assertEquals($sessionId, $response['body']['sessions'][0]['$id']);
        $this->assertEmpty($response['body']['sessions'][0]['secret']);

        $this->assertEquals('Windows', $response['body']['sessions'][0]['osName']);
        $this->assertEquals('WIN', $response['body']['sessions'][0]['osCode']);
        $this->assertEquals('10', $response['body']['sessions'][0]['osVersion']);

        $this->assertEquals('browser', $response['body']['sessions'][0]['clientType']);
        $this->assertEquals('Chrome', $response['body']['sessions'][0]['clientName']);
        $this->assertEquals('CH', $response['body']['sessions'][0]['clientCode']);
        $this->assertEquals('70.0', $response['body']['sessions'][0]['clientVersion']);
        $this->assertEquals('Blink', $response['body']['sessions'][0]['clientEngine']);
        $this->assertEquals('desktop', $response['body']['sessions'][0]['deviceName']);
        $this->assertEquals('', $response['body']['sessions'][0]['deviceBrand']);
        $this->assertEquals('', $response['body']['sessions'][0]['deviceModel']);
        $this->assertEquals($response['body']['sessions'][0]['ip'], filter_var($response['body']['sessions'][0]['ip'], FILTER_VALIDATE_IP));

        $this->assertEquals('--', $response['body']['sessions'][0]['countryCode']);
        $this->assertEquals('Unknown', $response['body']['sessions'][0]['countryName']);

        $this->assertEquals(true, $response['body']['sessions'][0]['current']);

        $this->assertNotFalse(\DateTime::createFromFormat('Y-m-d\TH:i:s.uP', $response['body']['sessions'][0]['expire']));
        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/account/sessions', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]));

        $this->assertEquals(401, $response['headers']['status-code']);

        return $data;
    }

    /**
     * @depends testCreateAccountSession
     */
    public function testGetAccountLogs($data): array
    {
        sleep(5);
        $session = $data['session'] ?? '';
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/account/logs', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertIsArray($response['body']['logs']);
        $this->assertNotEmpty($response['body']['logs']);
        $this->assertCount(3, $response['body']['logs']);
        $this->assertIsNumeric($response['body']['total']);
        $this->assertEquals("user.create", $response['body']['logs'][2]['event']);
        $this->assertEquals(filter_var($response['body']['logs'][2]['ip'], FILTER_VALIDATE_IP), $response['body']['logs'][2]['ip']);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($response['body']['logs'][2]['time']));

        $this->assertEquals('Windows', $response['body']['logs'][1]['osName']);
        $this->assertEquals('WIN', $response['body']['logs'][1]['osCode']);
        $this->assertEquals('10', $response['body']['logs'][1]['osVersion']);

        $this->assertEquals('browser', $response['body']['logs'][1]['clientType']);
        $this->assertEquals('Chrome', $response['body']['logs'][1]['clientName']);
        $this->assertEquals('CH', $response['body']['logs'][1]['clientCode']);
        $this->assertEquals('70.0', $response['body']['logs'][1]['clientVersion']);
        $this->assertEquals('Blink', $response['body']['logs'][1]['clientEngine']);

        $this->assertEquals('desktop', $response['body']['logs'][1]['deviceName']);
        $this->assertEquals('', $response['body']['logs'][1]['deviceBrand']);
        $this->assertEquals('', $response['body']['logs'][1]['deviceModel']);
        $this->assertEquals(filter_var($response['body']['logs'][1]['ip'], FILTER_VALIDATE_IP), $response['body']['logs'][1]['ip']);

        $this->assertEquals('--', $response['body']['logs'][1]['countryCode']);
        $this->assertEquals('Unknown', $response['body']['logs'][1]['countryName']);

        $this->assertEquals('Windows', $response['body']['logs'][2]['osName']);
        $this->assertEquals('WIN', $response['body']['logs'][2]['osCode']);
        $this->assertEquals('10', $response['body']['logs'][2]['osVersion']);

        $this->assertEquals('browser', $response['body']['logs'][2]['clientType']);
        $this->assertEquals('Chrome', $response['body']['logs'][2]['clientName']);
        $this->assertEquals('CH', $response['body']['logs'][2]['clientCode']);
        $this->assertEquals('70.0', $response['body']['logs'][2]['clientVersion']);
        $this->assertEquals('Blink', $response['body']['logs'][2]['clientEngine']);

        $this->assertEquals('desktop', $response['body']['logs'][2]['deviceName']);
        $this->assertEquals('', $response['body']['logs'][2]['deviceBrand']);
        $this->assertEquals('', $response['body']['logs'][2]['deviceModel']);
        $this->assertEquals($response['body']['logs'][2]['ip'], filter_var($response['body']['logs'][2]['ip'], FILTER_VALIDATE_IP));

        $this->assertEquals('--', $response['body']['logs'][2]['countryCode']);
        $this->assertEquals('Unknown', $response['body']['logs'][2]['countryName']);

        $responseLimit = $this->client->call(Client::METHOD_GET, '/account/logs', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'queries' => [
                Query::limit(1)->toString()
            ]
        ]);

        $this->assertEquals($responseLimit['headers']['status-code'], 200);
        $this->assertIsArray($responseLimit['body']['logs']);
        $this->assertNotEmpty($responseLimit['body']['logs']);
        $this->assertCount(1, $responseLimit['body']['logs']);
        $this->assertIsNumeric($responseLimit['body']['total']);

        $this->assertEquals($response['body']['logs'][0], $responseLimit['body']['logs'][0]);

        $responseOffset = $this->client->call(Client::METHOD_GET, '/account/logs', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'queries' => [
                Query::offset(1)->toString()
            ]
        ]);

        $this->assertEquals($responseOffset['headers']['status-code'], 200);
        $this->assertIsArray($responseOffset['body']['logs']);
        $this->assertNotEmpty($responseOffset['body']['logs']);
        $this->assertCount(2, $responseOffset['body']['logs']);
        $this->assertIsNumeric($responseOffset['body']['total']);

        $this->assertEquals($response['body']['logs'][1], $responseOffset['body']['logs'][0]);

        $responseLimitOffset = $this->client->call(Client::METHOD_GET, '/account/logs', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'queries' => [
                Query::offset(1)->toString(),
                Query::limit(1)->toString()
            ]
        ]);

        $this->assertEquals($responseLimitOffset['headers']['status-code'], 200);
        $this->assertIsArray($responseLimitOffset['body']['logs']);
        $this->assertNotEmpty($responseLimitOffset['body']['logs']);
        $this->assertCount(1, $responseLimitOffset['body']['logs']);
        $this->assertIsNumeric($responseLimitOffset['body']['total']);

        $this->assertEquals($response['body']['logs'][1], $responseLimitOffset['body']['logs'][0]);
        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/account/logs', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]));

        $this->assertEquals(401, $response['headers']['status-code']);

        return $data;
    }

    // TODO Add tests for OAuth2 session creation

    /**
     * @depends testCreateAccountSession
     */
    public function testUpdateAccountName($data): array
    {
        $email = $data['email'] ?? '';
        $session = $data['session'] ?? '';
        $newName = 'Lorem';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/account/name', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'name' => $newName
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertIsArray($response['body']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($response['body']['registration']));
        $this->assertEquals($response['body']['email'], $email);
        $this->assertEquals($response['body']['name'], $newName);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/account/name', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]));

        $this->assertEquals(401, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_PATCH, '/account/name', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), []);

        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_PATCH, '/account/name', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'name' => 'ocSRq1d3QphHivJyUmYY7WMnrxyjdk5YvVwcDqx2zS0coxESN8RmsQwLWw5Whnf0WbVohuFWTRAaoKgCOO0Y0M7LwgFnZmi8881Y72222222222222222222222222222'
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $data['name'] = $newName;

        return $data;
    }

    /**
     * @depends testUpdateAccountName
     */
    #[Retry(count: 1)]
    public function testUpdateAccountPassword($data): array
    {
        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';
        $session = $data['session'] ?? '';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/account/password', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'password' => 'new-password',
            'oldPassword' => $password,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertIsArray($response['body']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($response['body']['registration']));
        $this->assertEquals($response['body']['email'], $email);

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => 'new-password',
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/account/password', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]));

        $this->assertEquals(401, $response['headers']['status-code']);
        $response = $this->client->call(Client::METHOD_PATCH, '/account/password', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), []);

        $this->assertEquals(400, $response['headers']['status-code']);

        /**
         * Existing user tries to update password by passing wrong old password -> SHOULD FAIL
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/account/password', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'password' => 'new-password',
            'oldPassword' => $password,
        ]);
        $this->assertEquals(401, $response['headers']['status-code']);

        /**
         * Existing user tries to update password without passing old password -> SHOULD FAIL
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/account/password', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'password' => 'new-password'
        ]);
        $this->assertEquals(401, $response['headers']['status-code']);

        $data['password'] = 'new-password';

        return $data;
    }

    /**
     * @depends testUpdateAccountPassword
     */
    public function testUpdateAccountEmail($data): array
    {
        $newEmail = uniqid() . 'new@localhost.test';
        $session = $data['session'] ?? '';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/account/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'email' => $newEmail,
            'password' => 'new-password',
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertIsArray($response['body']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($response['body']['registration']));
        $this->assertEquals($response['body']['email'], $newEmail);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/account/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]));

        $this->assertEquals(401, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_PATCH, '/account/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), []);

        $this->assertEquals(400, $response['headers']['status-code']);

        // Test if we can create a new account with the old email

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => ID::unique(),
            'email' =>  $data['email'],
            'password' =>  $data['password'],
            'name' =>  $data['name'],
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($response['body']['registration']));
        $this->assertEquals($response['body']['email'], $data['email']);
        $this->assertEquals($response['body']['name'], $data['name']);


        $data['email'] = $newEmail;

        return $data;
    }

    /**
     * @depends testUpdateAccountEmail
     */
    public function testUpdateAccountPrefs($data): array
    {
        $newEmail = uniqid() . 'new@localhost.test';
        $session = $data['session'] ?? '';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/account/prefs', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'prefs' => [
                'prefKey1' => 'prefValue1',
                'prefKey2' => 'prefValue2',
            ]
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertIsArray($response['body']);
        $this->assertNotEmpty($response['body']);
        $this->assertEquals('prefValue1', $response['body']['prefs']['prefKey1']);
        $this->assertEquals('prefValue2', $response['body']['prefs']['prefKey2']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/account/prefs', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]));

        $this->assertEquals(401, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_PATCH, '/account/prefs', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'prefs' => '{}'
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);


        $response = $this->client->call(Client::METHOD_PATCH, '/account/prefs', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'prefs' => '[]'
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_PATCH, '/account/prefs', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'prefs' => '{"test": "value"}'
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        /**
         * Prefs size exceeded
         */
        $prefsObject = ["longValue" => str_repeat("🍰", 100000)];

        $response = $this->client->call(Client::METHOD_PATCH, '/account/prefs', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'prefs' => $prefsObject
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        // Now let's test the same thing, but with normal symbol instead of multi-byte cake emoji
        $prefsObject = ["longValue" => str_repeat("-", 100000)];

        $response = $this->client->call(Client::METHOD_PATCH, '/account/prefs', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'prefs' => $prefsObject
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        return $data;
    }

    /**
     * @depends testUpdateAccountPrefs
     */
    public function testCreateAccountVerification($data): array
    {
        $email = $data['email'] ?? '';
        $name = $data['name'] ?? '';
        $session = $data['session'] ?? '';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/account/verification', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,

        ]), [
            'url' => 'http://localhost/verification',
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEmpty($response['body']['secret']);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($response['body']['expire']));

        $lastEmail = $this->getLastEmail();

        $this->assertEquals($email, $lastEmail['to'][0]['address']);
        $this->assertEquals($name, $lastEmail['to'][0]['name']);
        $this->assertEquals('Account Verification', $lastEmail['subject']);

        $verification = substr($lastEmail['text'], strpos($lastEmail['text'], '&secret=', 0) + 8, 256);
        $expireTime = strpos($lastEmail['text'], 'expire=' . urlencode(DateTime::format(new \DateTime($response['body']['expire']))), 0);
        $this->assertNotFalse($expireTime);

        $secretTest = strpos($lastEmail['text'], 'secret=' . $response['body']['secret'], 0);

        $this->assertNotFalse($secretTest);

        $userIDTest = strpos($lastEmail['text'], 'userId=' . $response['body']['userId'], 0);

        $this->assertNotFalse($userIDTest);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_POST, '/account/verification', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'url' => 'localhost/verification',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/account/verification', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'url' => 'http://remotehost/verification',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $data['verification'] = $verification;

        return $data;
    }

    /**
     * @depends testCreateAccountVerification
     */
    public function testUpdateAccountVerification($data): array
    {
        $id = $data['id'] ?? '';
        $session = $data['session'] ?? '';
        $verification = $data['verification'] ?? '';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_PUT, '/account/verification', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'userId' => $id,
            'secret' => $verification,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_PUT, '/account/verification', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'userId' => ID::custom('ewewe'),
            'secret' => $verification,
        ]);

        $this->assertEquals(404, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_PUT, '/account/verification', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'userId' => $id,
            'secret' => 'sdasdasdasd',
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);

        return $data;
    }

    /**
     * @depends testUpdateAccountVerification
     */
    public function testDeleteAccountSession($data): array
    {
        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';
        $session = $data['session'] ?? '';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $sessionNewId = $response['body']['$id'];
        $sessionNew = $response['cookies']['a_session_' . $this->getProject()['$id']];

        $this->assertEquals(201, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/account', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $sessionNew,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_DELETE, '/account/sessions/' . $sessionNewId, array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $sessionNew,
        ]));

        $this->assertEquals(204, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals(200, $response['headers']['status-code']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/account', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $sessionNew,
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);

        return $data;
    }

    /**
     * @depends testUpdateAccountVerification
     */
    public function testDeleteAccountSessionCurrent($data): array
    {
        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $sessionNew = $response['cookies']['a_session_' . $this->getProject()['$id']];

        $this->assertEquals(201, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/account', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $sessionNew,
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_DELETE, '/account/sessions/current', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $sessionNew,
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        $this->assertEquals(204, $response['headers']['status-code']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/account', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $sessionNew,
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);

        return $data;
    }

    /**
     * @depends testUpdateAccountVerification
     */
    public function testDeleteAccountSessions($data): array
    {
        $session = $data['session'] ?? '';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_DELETE, '/account/sessions', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals(204, $response['headers']['status-code']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]));

        $this->assertEquals(401, $response['headers']['status-code']);

        /**
         * Create new fallback session
         */
        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $data['session'] = $response['cookies']['a_session_' . $this->getProject()['$id']];

        return $data;
    }

    /**
     * @depends testDeleteAccountSession
     */
    public function testCreateAccountRecovery($data): array
    {
        $email = $data['email'] ?? '';
        $name = $data['name'] ?? '';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/account/recovery', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'url' => 'http://localhost/recovery',
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEmpty($response['body']['secret']);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($response['body']['expire']));

        $lastEmail = $this->getLastEmail();

        $this->assertEquals($email, $lastEmail['to'][0]['address']);
        $this->assertEquals($name, $lastEmail['to'][0]['name']);
        $this->assertEquals('Password Reset', $lastEmail['subject']);

        $recovery = substr($lastEmail['text'], strpos($lastEmail['text'], '&secret=', 0) + 8, 256);

        $expireTime = strpos($lastEmail['text'], 'expire=' . urlencode(DateTime::format(new \DateTime($response['body']['expire']))), 0);

        $this->assertNotFalse($expireTime);

        $secretTest = strpos($lastEmail['text'], 'secret=' . $response['body']['secret'], 0);

        $this->assertNotFalse($secretTest);

        $userIDTest = strpos($lastEmail['text'], 'userId=' . $response['body']['userId'], 0);

        $this->assertNotFalse($userIDTest);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_POST, '/account/recovery', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'url' => 'localhost/recovery',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/account/recovery', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'url' => 'http://remotehost/recovery',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/account/recovery', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => 'not-found@localhost.test',
            'url' => 'http://localhost/recovery',
        ]);

        $this->assertEquals(404, $response['headers']['status-code']);

        $data['recovery'] = $recovery;

        return $data;
    }

    /**
     * @depends testCreateAccountRecovery
     */
    #[Retry(count: 1)]
    public function testUpdateAccountRecovery($data): array
    {
        $id = $data['id'] ?? '';
        $recovery = $data['recovery'] ?? '';
        $newPassword = 'test-recovery';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_PUT, '/account/recovery', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => $id,
            'secret' => $recovery,
            'password' => $newPassword,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_PUT, '/account/recovery', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => ID::custom('ewewe'),
            'secret' => $recovery,
            'password' => $newPassword,
        ]);

        $this->assertEquals(404, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_PUT, '/account/recovery', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => $id,
            'secret' => 'sdasdasdasd',
            'password' => $newPassword,
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);

        return $data;
    }

    /**
     * @depends testCreateAccountSession
     */
    public function testCreateOAuth2AccountSession(): array
    {
        $provider = 'mock';
        $appId = '1';
        $secret = '123456';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $this->getProject()['$id'] . '/oauth2', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ]), [
            'provider' => $provider,
            'appId' => $appId,
            'secret' => $secret,
            'enabled' => true,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/account/sessions/oauth2/' . $provider, array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'success' => 'http://localhost/v1/mock/tests/general/oauth2/success',
            'failure' => 'http://localhost/v1/mock/tests/general/oauth2/failure',
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('success', $response['body']['result']);

        /**
         * Test for Failure when disabled
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $this->getProject()['$id'] . '/oauth2', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ]), [
            'provider' => $provider,
            'appId' => $appId,
            'secret' => $secret,
            'enabled' => false,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/account/sessions/oauth2/' . $provider, array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'success' => 'http://localhost/v1/mock/tests/general/oauth2/success',
            'failure' => 'http://localhost/v1/mock/tests/general/oauth2/failure',
        ]);

        $this->assertEquals(412, $response['headers']['status-code']);

        return [];
    }

    public function testBlockedAccount(): array
    {
        $email = uniqid() . 'user@localhost.test';
        $password = 'password';
        $name = 'User Name (blocked)';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => ID::unique(),
            'email' => $email,
            'password' => $password,
            'name' => $name,
        ]);

        $id = $response['body']['$id'];

        $this->assertEquals(201, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        $sessionId = $response['body']['$id'];
        $session = $response['cookies']['a_session_' . $this->getProject()['$id']];

        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals(200, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_PATCH, '/users/' . $id . '/status', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'status' => false,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals(401, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);

        return [];
    }


    public function testSelfBlockedAccount(): array
    {
        $email = uniqid() . 'user55@localhost.test';
        $password = 'password';
        $name = 'User Name (self blocked)';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => ID::unique(),
            'email' => $email,
            'password' => $password,
            'name' => $name,
        ]);

        $id = $response['body']['$id'];

        $this->assertEquals(201, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        $session = $response['cookies']['a_session_' . $this->getProject()['$id']];

        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals(200, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_PATCH, '/account/status', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ], [
            'status' => false,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertStringContainsString('a_session_' . $this->getProject()['$id'] . '=deleted', $response['headers']['set-cookie']);
        $this->assertEquals('[]', $response['headers']['x-fallback-cookies']);

        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals(401, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);

        return [];
    }

    public function testCreateJWT(): array
    {
        $email = uniqid() . 'user@localhost.test';
        $password = 'password';
        $name = 'User Name (JWT)';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => ID::unique(),
            'email' => $email,
            'password' => $password,
            'name' => $name,
        ]);

        $id = $response['body']['$id'];

        $this->assertEquals(201, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        $sessionId = $response['body']['$id'];
        $session = $response['cookies']['a_session_' . $this->getProject()['$id']];

        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals(200, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/account/jwt', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEquals(99, $response['headers']['x-ratelimit-remaining']);
        $this->assertNotEmpty($response['body']['jwt']);
        $this->assertIsString($response['body']['jwt']);

        $jwt = $response['body']['jwt'];

        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-jwt' => 'wrong-token',
        ]));

        $this->assertEquals(401, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-jwt' => $jwt,
        ]));

        $this->assertEquals(200, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_DELETE, '/account/sessions/' . $sessionId, array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals(204, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-jwt' => $jwt,
        ]));

        $this->assertEquals(401, $response['headers']['status-code']);

        return [];
    }

    public function testCreateAnonymousAccount()
    {
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/anonymous', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertIsArray($response['body']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEmpty($response['body']['secret']);

        $session = $response['cookies']['a_session_' . $this->getProject()['$id']];

        \usleep(1000 * 30); // wait for 30ms to let the shutdown update accessedAt

        $apiKey = $this->getProject()['apiKey'];
        $userId = $response['body']['userId'];
        $response = $this->client->call(Client::METHOD_GET, '/users/' . $userId, array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $apiKey,
        ]));
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertArrayHasKey('accessedAt', $response['body']);

        $this->assertNotEmpty($response['body']['accessedAt']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/anonymous', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);

        return $session;
    }

    /**
     * @depends testCreateAnonymousAccount
     */
    public function testUpdateAnonymousAccountPassword($session)
    {
        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/account/password', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'oldPassword' => '',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        return $session;
    }

    /**
     * @depends testUpdateAnonymousAccountPassword
     */
    public function testUpdateAnonymousAccountEmail($session)
    {
        $email = uniqid() . 'new@localhost.test';

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/account/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'email' => $email,
            'password' => '',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        return [];
    }

    public function testConvertAnonymousAccount()
    {
        $session = $this->testCreateAnonymousAccount();
        $email = uniqid() . 'new@localhost.test';
        $password = 'new-password';

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_POST, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => ID::unique(),
            'email' => $email,
            'password' => $password
        ]);

        $response = $this->client->call(Client::METHOD_PATCH, '/account/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertEquals(409, $response['headers']['status-code']);

        /**
         * Test for SUCCESS
         */
        $email = uniqid() . 'new@localhost.test';

        $response = $this->client->call(Client::METHOD_PATCH, '/account/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertIsArray($response['body']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($response['body']['registration']));
        $this->assertEquals($response['body']['email'], $email);

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/account/verification', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'url' => 'http://localhost'
        ]);


        $this->assertEquals(201, $response['headers']['status-code']);

        return [];
    }

    public function testConvertAnonymousAccountOAuth2()
    {
        $session = $this->testCreateAnonymousAccount();
        $provider = 'mock';
        $appId = '1';
        $secret = '123456';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals(200, $response['headers']['status-code']);

        $userId = $response['body']['$id'] ?? '';

        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $this->getProject()['$id'] . '/oauth2', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ]), [
            'provider' => $provider,
            'appId' => $appId,
            'secret' => $secret,
            'enabled' => true,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/account/sessions/oauth2/' . $provider, array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'success' => 'http://localhost/v1/mock/tests/general/oauth2/success',
            'failure' => 'http://localhost/v1/mock/tests/general/oauth2/failure',
        ]);

        $session = $response['cookies']['a_session_' . $this->getProject()['$id']];

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('success', $response['body']['result']);

        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals($response['body']['$id'], $userId);
        $this->assertEquals($response['body']['name'], 'User Name');
        $this->assertEquals($response['body']['email'], 'useroauth@localhost.test');

        // Since we only support one oauth user, let's also check updateSession here

        $this->assertEquals(200, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/account/sessions/current', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEmpty($response['body']['secret']);
        $this->assertEquals('123456', $response['body']['providerAccessToken']);
        $this->assertEquals('tuvwxyz', $response['body']['providerRefreshToken']);
        $this->assertGreaterThan(DateTime::addSeconds(new \DateTime(), 14400 - 5), $response['body']['providerAccessTokenExpiry']); // 5 seconds allowed networking delay

        $initialExpiry = $response['body']['providerAccessTokenExpiry'];

        sleep(3);

        $response = $this->client->call(Client::METHOD_PATCH, '/account/sessions/current', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('123456', $response['body']['providerAccessToken']);
        $this->assertEquals('tuvwxyz', $response['body']['providerRefreshToken']);
        $this->assertNotEquals($initialExpiry, $response['body']['providerAccessTokenExpiry']);

        return [];
    }

    public function testGetSessionByID()
    {
        $session = $this->testCreateAnonymousAccount();

        $response = $this->client->call(Client::METHOD_GET, '/account/sessions/current', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEmpty($response['body']['secret']);
        $this->assertEquals($response['body']['provider'], 'anonymous');

        $sessionID = $response['body']['$id'];

        $response = $this->client->call(Client::METHOD_GET, '/account/sessions/' . $sessionID, array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEmpty($response['body']['secret']);
        $this->assertEquals($response['body']['provider'], 'anonymous');

        $response = $this->client->call(Client::METHOD_GET, '/account/sessions/97823askjdkasd80921371980', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals(404, $response['headers']['status-code']);
    }

    /**
     * @depends testUpdateAccountName
     */
    public function testUpdateAccountNameSearch($data): void
    {
        $id = $data['id'] ?? '';
        $newName = 'Lorem';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/users', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'search' => $newName,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['users']);
        $this->assertCount(2, $response['body']['users']);
        $this->assertEquals($newName, $response['body']['users'][1]['name']);

        $response = $this->client->call(Client::METHOD_GET, '/users', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'search' => $id,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['users']);
        $this->assertCount(1, $response['body']['users']);
        $this->assertEquals($newName, $response['body']['users'][0]['name']);
    }

    /**
     * @depends testUpdateAccountEmail
     */
    public function testUpdateAccountEmailSearch($data): void
    {
        $id = $data['id'] ?? '';
        $email = $data['email'] ?? '';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/users', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'search' => '"' . $email . '"',

        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['users']);
        $this->assertCount(1, $response['body']['users']);
        $this->assertEquals($response['body']['users'][0]['email'], $email);

        $response = $this->client->call(Client::METHOD_GET, '/users', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'search' => $id,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['users']);
        $this->assertCount(1, $response['body']['users']);
        $this->assertEquals($response['body']['users'][0]['email'], $email);
    }

    #[Retry(count: 2)]
    public function testCreatePhone(): array
    {
        $number = '+123456789';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/account/tokens/phone', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => ID::unique(),
            'phone' => $number,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEmpty($response['body']['secret']);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($response['body']['expire']));

        $userId = $response['body']['userId'];

        \sleep(7);

        $smsRequest = $this->getLastRequest();

        $this->assertEquals('http://request-catcher:5000/mock-sms', $smsRequest['url']);
        $this->assertEquals('Appwrite Mock Message Sender', $smsRequest['headers']['User-Agent']);
        $this->assertEquals('username', $smsRequest['headers']['X-Username']);
        $this->assertEquals('password', $smsRequest['headers']['X-Key']);
        $this->assertEquals('POST', $smsRequest['method']);
        $this->assertEquals('+123456789', $smsRequest['data']['from']);
        $this->assertEquals($number, $smsRequest['data']['to']);

        $data['token'] = $smsRequest['data']['message'];
        $data['id'] = $userId;
        $data['number'] = $number;

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_POST, '/account/tokens/phone', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => ID::unique()
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        return $data;
    }

    /**
     * @depends testCreatePhone
     */
    public function testCreateSessionWithPhone(array $data): array
    {
        $id = $data['id'] ?? '';
        $token = explode(" ", $data['token'])[0] ?? '';
        $number = $data['number'] ?? '';

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_PUT, '/account/sessions/phone', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => ID::custom('ewewe'),
            'secret' => $token,
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_PUT, '/account/sessions/phone', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => $id,
            'secret' => 'sdasdasdasd',
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_PUT, '/account/sessions/phone', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => $id,
            'secret' => $token,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertIsArray($response['body']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertNotEmpty($response['body']['userId']);

        $session = $response['cookies']['a_session_' . $this->getProject()['$id']];

        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($response['body']['registration']));
        $this->assertEquals($response['body']['phone'], $number);
        $this->assertTrue($response['body']['phoneVerification']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_PUT, '/account/sessions/phone', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => $id,
            'secret' => $token,
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);

        $data['session'] = $session;

        return $data;
    }

    /**
     * @depends testCreateSessionWithPhone
     */
    public function testConvertPhoneToPassword(array $data): array
    {
        $session = $data['session'];
        $email = uniqid() . 'new@localhost.test';
        $password = 'new-password';

        /**
         * Test for SUCCESS
         */
        $email = uniqid() . 'new@localhost.test';

        $response = $this->client->call(Client::METHOD_PATCH, '/account/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertIsArray($response['body']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($response['body']['registration']));
        $this->assertEquals($response['body']['email'], $email);

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        return $data;
    }

    /**
     * @depends testConvertPhoneToPassword
     */
    public function testUpdatePhone(array $data): array
    {
        $newPhone = '+45632569856';
        $session = $data['session'] ?? '';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/account/phone', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'phone' => $newPhone,
            'password' => 'new-password'
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertIsArray($response['body']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($response['body']['registration']));
        $this->assertEquals($response['body']['phone'], $newPhone);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/account/phone', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]));

        $this->assertEquals(401, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_PATCH, '/account/phone', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), []);

        $this->assertEquals(400, $response['headers']['status-code']);

        $data['phone'] = $newPhone;

        return $data;
    }

    /**
     * @depends testGetAccountSessions
     * @depends testGetAccountLogs
     */
    public function testCreateSession(array $data): array
    {
        $response = $this->client->call(Client::METHOD_POST, '/users/' . $data['id'] . '/tokens', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'expire' => 60
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        $userId = $response['body']['userId'];
        $secret = $response['body']['secret'];

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/token', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'userId' => $userId,
            'secret' => $secret
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEquals($data['id'], $response['body']['userId']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertNotEmpty($response['body']['expire']);
        $this->assertEmpty($response['body']['secret']);

        /**
         * Test for FAILURE
         */
        // Invalid userId
        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/token', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'userId' => ID::custom('ewewe'),
            'secret' => $secret,
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);

        // Invalid secret
        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/token', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'userId' => $userId,
            'secret' => '123456',
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);

        return $data;
    }

    /**
     * @depends testUpdatePhone
     */
    #[Retry(count: 3)]
    public function testPhoneVerification(array $data): array
    {
        $session = $data['session'] ?? '';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/account/verification/phone', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEmpty($response['body']['secret']);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($response['body']['expire']));

        $smsRequest = $this->getLastRequest();

        $message = $smsRequest['data']['message'];
        $token = substr($message, 0, 6);

        return \array_merge($data, [
            'token' => \substr($smsRequest['data']['message'], 0, 6)
        ]);
    }

    /**
     * @depends testPhoneVerification
     */
    public function testUpdatePhoneVerification($data): array
    {
        $id = $data['id'] ?? '';
        $session = $data['session'] ?? '';
        $secret = $data['token'] ?? '';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_PUT, '/account/verification/phone', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'userId' => $id,
            'secret' => $secret,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_PUT, '/account/verification/phone', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'userId' => ID::custom('ewewe'),
            'secret' => $secret,
        ]);

        $this->assertEquals(404, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_PUT, '/account/verification/phone', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'userId' => $id,
            'secret' => '999999',
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);

        return $data;
    }

    public function testCreateMagicUrl(): array
    {
        $email = \time() . 'user@appwrite.io';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/account/tokens/magic-url', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => ID::unique(),
            'email' => $email,
            // 'url' => 'http://localhost/magiclogin',
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEmpty($response['body']['secret']);
        $this->assertEmpty($response['body']['phrase']);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($response['body']['expire']));

        $userId = $response['body']['userId'];

        $lastEmail = $this->getLastEmail();
        $this->assertEquals($email, $lastEmail['to'][0]['address']);
        $this->assertEquals($this->getProject()['name'] . ' Login', $lastEmail['subject']);
        $this->assertStringNotContainsStringIgnoringCase('security phrase', $lastEmail['text']);

        $token = substr($lastEmail['text'], strpos($lastEmail['text'], '&secret=', 0) + 8, 64);

        $expireTime = strpos($lastEmail['text'], 'expire=' . urlencode($response['body']['expire']), 0);

        $this->assertNotFalse($expireTime);

        $secretTest = strpos($lastEmail['text'], 'secret=' . $response['body']['secret'], 0);

        $this->assertNotFalse($secretTest);

        $userIDTest = strpos($lastEmail['text'], 'userId=' . $response['body']['userId'], 0);

        $this->assertNotFalse($userIDTest);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_POST, '/account/tokens/magic-url', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => ID::unique(),
            'email' => $email,
            'url' => 'localhost/magiclogin',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/account/tokens/magic-url', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => ID::unique(),
            'email' => $email,
            'url' => 'http://remotehost/magiclogin',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/account/tokens/magic-url', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/magic-url', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => ID::unique(),
            'email' => $email,
            'phrase' => true
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertNotEmpty($response['body']['phrase']);

        $lastEmail = $this->getLastEmail();
        $this->assertStringContainsStringIgnoringCase($response['body']['phrase'], $lastEmail['text']);

        $data['token'] = $token;
        $data['id'] = $userId;
        $data['email'] = $email;

        return $data;
    }

    /**
     * @depends testCreateMagicUrl
     */
    public function testCreateSessionWithMagicUrl($data): array
    {
        $id = $data['id'] ?? '';
        $token = $data['token'] ?? '';
        $email = $data['email'] ?? '';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_PUT, '/account/sessions/magic-url', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => $id,
            'secret' => $token,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertIsArray($response['body']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertNotEmpty($response['body']['userId']);
        $this->assertEmpty($response['body']['secret']);

        $sessionId = $response['body']['$id'];
        $session = $response['cookies']['a_session_' . $this->getProject()['$id']];

        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($response['body']['registration']));
        $this->assertEquals($response['body']['email'], $email);
        $this->assertTrue($response['body']['emailVerification']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_PUT, '/account/sessions/magic-url', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => ID::custom('ewewe'),
            'secret' => $token,
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_PUT, '/account/sessions/magic-url', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => $id,
            'secret' => 'sdasdasdasd',
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);


        $data['sessionId'] = $sessionId;
        $data['session'] = $session;

        return $data;
    }

    /**
     * @depends testCreateSessionWithMagicUrl
     */
    public function testUpdateAccountPasswordWithMagicUrl($data): array
    {
        $email = $data['email'] ?? '';
        $session = $data['session'] ?? '';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/account/password', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'password' => 'new-password'
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertIsArray($response['body']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($response['body']['registration']));
        $this->assertEquals($response['body']['email'], $email);

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => 'new-password',
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEmpty($response['body']['secret']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/account/password', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]));

        $this->assertEquals(401, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_PATCH, '/account/password', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), []);

        $this->assertEquals(400, $response['headers']['status-code']);

        /**
         * Existing user tries to update password by passing wrong old password -> SHOULD FAIL
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/account/password', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'password' => 'new-password',
            'oldPassword' => 'wrong-password',
        ]);
        $this->assertEquals(401, $response['headers']['status-code']);

        /**
         * Existing user tries to update password without passing old password -> SHOULD FAIL
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/account/password', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'password' => 'new-password'
        ]);
        $this->assertEquals(401, $response['headers']['status-code']);

        $data['password'] = 'new-password';

        return $data;
    }
}
