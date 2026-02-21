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
        $this->assertTrue((new DatetimeValidator())->isValid($response['body']['registration']));
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
        $this->assertTrue((new DatetimeValidator())->isValid($response['body']['logs'][2]['time']));

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

        $this->assertEquals(200, $responseLimit['headers']['status-code']);
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

        $this->assertEquals(200, $responseLimitOffset['headers']['status-code']);
        $this->assertIsArray($responseLimitOffset['body']['logs']);
        $this->assertNotEmpty($responseLimitOffset['body']['logs']);
        $this->assertCount(1, $responseLimitOffset['body']['logs']);
        $this->assertIsNumeric($responseLimitOffset['body']['total']);

        $this->assertEquals($response['body']['logs'][1], $responseLimitOffset['body']['logs'][0]);

        /**
         * Test for total=false
         */
        $logsWithIncludeTotalFalse = $this->client->call(Client::METHOD_GET, '/account/logs', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'total' => false
        ]);

        $this->assertEquals(200, $logsWithIncludeTotalFalse['headers']['status-code']);
        $this->assertIsArray($logsWithIncludeTotalFalse['body']);
        $this->assertIsArray($logsWithIncludeTotalFalse['body']['logs']);
        $this->assertIsInt($logsWithIncludeTotalFalse['body']['total']);
        $this->assertEquals(0, $logsWithIncludeTotalFalse['body']['total']);
        $this->assertGreaterThan(0, count($logsWithIncludeTotalFalse['body']['logs']));

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
        $this->assertTrue((new DatetimeValidator())->isValid($response['body']['registration']));
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

        for ($i = 0; $i < 5; $i++) {
            $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
                'origin' => 'http://localhost',
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ]), [
                'email' => $email,
                'password' => $password,
            ]);

            $this->assertEquals(201, $response['headers']['status-code']);
            sleep(1);
        }

        $response = $this->client->call(Client::METHOD_GET, '/account/sessions', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $allSessions = array_map(fn ($sessionDetails) => $sessionDetails['$id'], $response['body']['sessions']);

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
        $this->assertTrue((new DatetimeValidator())->isValid($response['body']['registration']));
        $this->assertEquals($response['body']['email'], $email);

        $currentSessionId = $data['sessionId'] ?? '';
        $response = $this->client->call(Client::METHOD_GET, '/account/sessions', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(1, $response['body']['total']);
        // checking the current session or not
        $this->assertEquals($currentSessionId, $response['body']['sessions'][0]['$id']);
        $this->assertTrue($response['body']['sessions'][0]['current']);

        // checking for all non active sessions are cleared
        foreach ($allSessions as $sessionId) {
            if ($currentSessionId === $sessionId) {
                $response = $this->client->call(Client::METHOD_GET, '/account/sessions/current', array_merge([
                    'origin' => 'http://localhost',
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                    'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
                ]));

                $this->assertEquals(200, $response['headers']['status-code']);
            } else {
                $response = $this->client->call(Client::METHOD_GET, '/account/sessions/'.$sessionId, array_merge([
                    'origin' => 'http://localhost',
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                    'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
                ]));

                $this->assertEquals(404, $response['headers']['status-code']);
            }
        }

        $newPassword = 'new-password';
        // updating the invalidateSession to false to check sessions are not invalidated
        $this->updateProjectinvalidateSessionsProperty(false);
        for ($i = 0; $i < 5; $i++) {
            $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
                'origin' => 'http://localhost',
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ]), [
                'email' => $email,
                'password' => $newPassword,
            ]);

            $this->assertEquals(201, $response['headers']['status-code']);
            sleep(1);
        }

        $response = $this->client->call(Client::METHOD_GET, '/account/sessions', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $allSessions = array_map(fn ($sessionDetails) => $sessionDetails['$id'], $response['body']['sessions']);

        $response = $this->client->call(Client::METHOD_PATCH, '/account/password', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'password' => $newPassword,
            'oldPassword' => $newPassword,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        foreach ($allSessions as $sessionId) {
            $response = $this->client->call(Client::METHOD_GET, '/account/sessions/'.$sessionId, headers: array_merge([
                'origin' => 'http://localhost',
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
            ]));

            $this->assertEquals(200, $response['headers']['status-code']);
        }

        // setting invalidateSession to true to check the sessions are cleared or not
        $this->updateProjectinvalidateSessionsProperty(true);
        $response = $this->client->call(Client::METHOD_PATCH, '/account/password', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'password' => $newPassword,
            'oldPassword' => $newPassword,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/account/sessions', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $allSessions = array_map(fn ($sessionDetails) => $sessionDetails['$id'], $response['body']['sessions']);

        foreach ($allSessions as $sessionId) {
            if ($currentSessionId !== $sessionId) {
                $response = $this->client->call(Client::METHOD_GET, '/account/sessions/'.$sessionId, array_merge([
                    'origin' => 'http://localhost',
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                    'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
                ]));

                $this->assertEquals(404, $response['headers']['status-code']);
            }
        }

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => $newPassword,
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
        $this->assertTrue((new DatetimeValidator())->isValid($response['body']['registration']));
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
            'x-appwrite-dev-key' => $this->getProject()['devKey'] ?? ''
        ]), [
            'userId' => ID::unique(),
            'email' => $data['email'],
            'password' => $data['password'],
            'name' => $data['name'],
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertTrue((new DatetimeValidator())->isValid($response['body']['registration']));
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
        $this->assertTrue((new DatetimeValidator())->isValid($response['body']['expire']));

        $lastEmail = $this->getLastEmail();

        $this->assertEquals($email, $lastEmail['to'][0]['address']);
        $this->assertEquals($name, $lastEmail['to'][0]['name']);
        $this->assertEquals('Account Verification for ' . $this->getProject()['name'], $lastEmail['subject']);
        $this->assertStringContainsStringIgnoringCase('Verify your email to activate your ' . $this->getProject()['name'] . ' account.', $lastEmail['text']);

        $tokens = $this->extractQueryParamsFromEmailLink($lastEmail['html']);
        $verification = $tokens['secret'];
        $expectedExpire = DateTime::formatTz($response['body']['expire']);
        $this->assertEquals($expectedExpire, $tokens['expire']);

        // Secret check
        $this->assertArrayHasKey('secret', $tokens);
        $this->assertNotEmpty($tokens['secret']);

        // User ID check
        $this->assertArrayHasKey('userId', $tokens);
        $this->assertNotEmpty($tokens['userId']);

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
        $this->assertTrue((new DatetimeValidator())->isValid($response['body']['expire']));

        $lastEmail = $this->getLastEmail();

        $this->assertEquals($email, $lastEmail['to'][0]['address']);
        $this->assertEquals($name, $lastEmail['to'][0]['name']);
        $this->assertEquals('Password Reset for ' . $this->getProject()['name'], $lastEmail['subject']);
        $this->assertStringContainsStringIgnoringCase('Reset your ' . $this->getProject()['name'] . ' password using the link.', $lastEmail['text']);


        $tokens = $this->extractQueryParamsFromEmailLink($lastEmail['html']);

        // Secret check
        $this->assertArrayHasKey('secret', $tokens);
        $this->assertNotEmpty($tokens['secret']);
        $this->assertNotFalse($response['body']['secret']);

        // User ID check
        $this->assertArrayHasKey('userId', $tokens);
        $this->assertNotEmpty($tokens['userId']);
        $this->assertNotFalse($response['body']['userId']);

        // Expire check
        $this->assertArrayHasKey('expire', $tokens);
        $this->assertNotEmpty($tokens['expire']);
        $this->assertEquals(
            DateTime::formatTz($response['body']['expire']),
            $tokens['expire']
        );

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

        $data['recovery'] = $tokens['secret'];

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

    public function testSessionAlert(): void
    {
        $email = uniqid() . 'session-alert@appwrite.io';
        $password = 'password123';
        $name = 'Session Alert Tester';

        // Enable session alerts
        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $this->getProject()['$id'] . '/auth/session-alerts', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ]), [
            'alerts' => true,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        // Create a new account
        $response = $this->client->call(Client::METHOD_POST, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-dev-key' => $this->getProject()['devKey'] ?? ''
        ]), [
            'userId' => ID::unique(),
            'email' => $email,
            'password' => $password,
            'name' => $name,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        // Create first session for the new account
        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'user-agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        // Create second session for the new account
        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'user-agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
        ]), [
            'email' => $email,
            'password' => $password,
        ]);


        // Check the alert email
        $lastEmail = $this->getLastEmail();

        $this->assertEquals($email, $lastEmail['to'][0]['address']);
        $this->assertStringContainsString('Security alert: new session', $lastEmail['subject']);
        $this->assertStringContainsString($response['body']['ip'], $lastEmail['text']); // IP Address
        $this->assertStringContainsString('Unknown', $lastEmail['text']); // Country
        $this->assertStringContainsString($response['body']['clientName'], $lastEmail['text']); // Client name
        $this->assertStringNotContainsStringIgnoringCase('Appwrite logo', $lastEmail['html']);

        // Verify no alert sent in OTP login
        $response = $this->client->call(Client::METHOD_POST, '/account/tokens/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => ID::unique(),
            'email' => 'otpuser2@appwrite.io'
        ]);

        $this->assertEquals($response['headers']['status-code'], 201);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertNotEmpty($response['body']['$createdAt']);
        $this->assertNotEmpty($response['body']['userId']);
        $this->assertNotEmpty($response['body']['expire']);
        $this->assertEmpty($response['body']['secret']);
        $this->assertEmpty($response['body']['phrase']);
        $this->assertStringContainsStringIgnoringCase('New login detected on '. $this->getProject()['name'], $lastEmail['text']);

        $userId = $response['body']['userId'];

        $lastEmail = $this->getLastEmail();

        $this->assertEquals('otpuser2@appwrite.io', $lastEmail['to'][0]['address']);
        $this->assertEquals('OTP for ' . $this->getProject()['name'] . ' Login', $lastEmail['subject']);

        // Find 6 concurrent digits in email text - OTP
        preg_match_all("/\b\d{6}\b/", $lastEmail['text'], $matches);
        $code = ($matches[0] ?? [])[0] ?? '';

        $this->assertNotEmpty($code);

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/token', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => $userId,
            'secret' => $code
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEquals($userId, $response['body']['userId']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertNotEmpty($response['body']['expire']);
        $this->assertEmpty($response['body']['secret']);

        $lastEmailId = $lastEmail['id'];
        $lastEmail = $this->getLastEmail();
        $this->assertEquals($lastEmailId, $lastEmail['id']);
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

    public function testCreateOidcOAuth2Token(): array
    {
        $provider = 'oidc';
        $appId = '1';

        // Valid well-known configuration
        $secret = '{
            "wellKnownEndpoint": "https://accounts.google.com/.well-known/openid-configuration",
            "authorizationEndpoint": "https://accounts.google.com/o/oauth2/v2/auth",
            "tokenEndpoint": "https://oauth2.googleapis.com/token",
            "userinfoEndpoint": "https://openidconnect.googleapis.com/v1/userinfo"
        }';

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

        $response = $this->client->call(Client::METHOD_GET, '/account/tokens/oauth2/' . $provider, array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'provider' => $provider,
            'success' => 'http://localhost/v1/mock/tests/general/oauth2/success',
            'failure' => 'http://localhost/v1/mock/tests/general/oauth2/failure',
        ], true, false);

        $this->assertEquals(301, $response['headers']['status-code']);

        // Invalid well-known configuration
        $secret = '{}';

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

        $response = $this->client->call(Client::METHOD_GET, '/account/tokens/oauth2/' . $provider, array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'provider' => $provider,
            'success' => 'http://localhost/v1/mock/tests/general/oauth2/success',
            'failure' => 'http://localhost/v1/mock/tests/general/oauth2/failure',
        ]);

        $this->assertEquals(500, $response['headers']['status-code']);

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
    public function testCreateAnonymousAccountVerification($session): array
    {
        $response = $this->client->call(Client::METHOD_POST, '/account/verification', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'url' => 'http://localhost/verification',
        ]);

        $this->assertEquals(400, $response['body']['code']);
        $this->assertEquals('user_email_not_found', $response['body']['type']);

        return [];
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
        $this->assertTrue((new DatetimeValidator())->isValid($response['body']['registration']));
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

        $this->assertEquals(200, $response['headers']['status-code']);

        $sessionCookieKey = 'a_session_' . $this->getProject()['$id'];
        $this->assertArrayHasKey(
            $sessionCookieKey,
            $response['cookies'],
            "Failed asserting that session cookie '$sessionCookieKey' is set. Cookies: " . json_encode($response['cookies'])
        );
        $session = $response['cookies'][$sessionCookieKey];

        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals($response['body']['$id'], $userId);
        $this->assertEquals('User Name', $response['body']['name']);
        $this->assertEquals('useroauth@localhost.test', $response['body']['email']);

        // Since we only support one oauth user, let's also check updateSession here

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

        // Clean up - delete the user
        $response = $this->client->call(Client::METHOD_DELETE, '/users/' . $userId, array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]));

        $this->assertEquals(204, $response['headers']['status-code']);

        return [];
    }

    public function testOAuthUnverifiedEmailCannotLinkToExistingAccount()
    {
        $provider = 'mock-unverified';
        $appId = '1';
        $secret = '123456';

        // First, create a user with the same email that the unverified OAuth will try to use
        $email = 'useroauthunverified@localhost.test';
        $password = 'password';

        $response = $this->client->call(Client::METHOD_POST, '/account', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'userId' => ID::unique(),
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $existingUserId = $response['body']['$id'];

        // Enable the mock-unverified provider
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

        // Attempt OAuth login with unverified email - should fail because existing user has same email
        $response = $this->client->call(Client::METHOD_GET, '/account/sessions/oauth2/' . $provider, array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'success' => 'http://localhost/v1/mock/tests/general/oauth2/success',
            'failure' => 'http://localhost/v1/mock/tests/general/oauth2/failure',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
        $this->assertEquals('failure', $response['body']['result']);

        // Clean up - delete the user
        $response = $this->client->call(Client::METHOD_DELETE, '/users/' . $existingUserId, array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]));

        $this->assertEquals(204, $response['headers']['status-code']);

        return [];
    }

    public function testOAuthVerifiedEmailCanLinkToExistingAccount()
    {
        $provider = 'mock';
        $appId = '1';
        $secret = '123456';
        $email = 'useroauth@localhost.test';

        // Create a user with the same email that the verified OAuth will try to use
        $response = $this->client->call(Client::METHOD_POST, '/account', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'userId' => ID::unique(),
            'email' => $email,
            'password' => 'password',
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $existingUserId = $response['body']['$id'];

        // Enable the mock provider
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

        // Attempt OAuth login with verified email - should succeed and link to existing account
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

        // Verify the OAuth identity was linked to the existing user
        $sessionCookieKey = 'a_session_' . $this->getProject()['$id'];
        $session = $response['cookies'][$sessionCookieKey];

        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals($existingUserId, $response['body']['$id']);
        $this->assertEquals($email, $response['body']['email']);

        // Clean up - delete the user
        $response = $this->client->call(Client::METHOD_DELETE, '/users/' . $existingUserId, array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]));

        $this->assertEquals(204, $response['headers']['status-code']);

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
        $this->assertEquals('anonymous', $response['body']['provider']);

        $sessionID = $response['body']['$id'];

        $response = $this->client->call(Client::METHOD_GET, '/account/sessions/' . $sessionID, array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEmpty($response['body']['secret']);
        $this->assertEquals('anonymous', $response['body']['provider']);

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
        $this->assertTrue((new DatetimeValidator())->isValid($response['body']['expire']));

        $userId = $response['body']['userId'];

        $smsRequest = $this->assertLastRequest(function (array $request) use ($number) {
            $this->assertEquals('Appwrite Mock Message Sender', $request['headers']['User-Agent']);
            $this->assertEquals('username', $request['headers']['X-Username']);
            $this->assertEquals('password', $request['headers']['X-Key']);
            $this->assertEquals('POST', $request['method']);
            $this->assertEquals('+123456789', $request['data']['from']);
            $this->assertEquals($number, $request['data']['to']);
        }, Scope::REQUEST_TYPE_SMS);

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
        $this->assertTrue((new DatetimeValidator())->isValid($response['body']['registration']));
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
        $this->assertTrue((new DatetimeValidator())->isValid($response['body']['registration']));
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
        $this->assertEquals('browser', $response['body']['clientType']);
        $this->assertEquals('CH', $response['body']['clientCode']);
        $this->assertEquals('Chrome', $response['body']['clientName']);

        // Forwarded User Agent with API Key
        $response = $this->client->call(Client::METHOD_POST, '/users/' . $data['id'] . '/tokens', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'expire' => 60
        ]);

        $userId = $response['body']['userId'];
        $secret = $response['body']['secret'];

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/token', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
            'x-forwarded-user-agent' => 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/114.0.0.0 Mobile Safari/537.36'
        ], [
            'userId' => $userId,
            'secret' => $secret
        ]);

        $this->assertEquals('browser', $response['body']['clientType']);
        $this->assertEquals('CM', actual: $response['body']['clientCode']);
        $this->assertEquals('Chrome Mobile', $response['body']['clientName']);

        // Forwarded User Agent without API Key
        $response = $this->client->call(Client::METHOD_POST, '/users/' . $data['id'] . '/tokens', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'expire' => 60
        ]);

        $userId = $response['body']['userId'];
        $secret = $response['body']['secret'];

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/token', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-forwarded-user-agent' => 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/114.0.0.0 Mobile Safari/537.36'
        ], [
            'userId' => $userId,
            'secret' => $secret
        ]);

        $this->assertEquals('browser', $response['body']['clientType']);
        $this->assertEquals('CH', $response['body']['clientCode']);
        $this->assertEquals('Chrome', $response['body']['clientName']);

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
        $this->assertNotEmpty($response['body']['$createdAt']);
        $this->assertEmpty($response['body']['secret']);
        $this->assertTrue((new DatetimeValidator())->isValid($response['body']['expire']));

        $tokenCreatedAt = $response['body']['$createdAt'];

        $smsRequest = $this->assertLastRequest(function ($request) use ($tokenCreatedAt) {
            $this->assertArrayHasKey('data', $request);
            $this->assertArrayHasKey('time', $request);
            $this->assertArrayHasKey('message', $request['data'], "Last request missing message: " . \json_encode($request));

            // Ensure we are not using token from last sms login
            $tokenRecievedAt = $request['time'];
            $this->assertGreaterThan($tokenCreatedAt, $tokenRecievedAt);
        }, Scope::REQUEST_TYPE_SMS);

        /**
         * Test for FAILURE
         */

        // disable phone sessions
        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $this->getProject()['$id'] . '/auth/phone', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ]), [
            'status' => false,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(false, $response['body']['authPhone']);

        $response = $this->client->call(Client::METHOD_POST, '/account/verification/phone', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals(501, $response['headers']['status-code']);
        $this->assertEquals("Phone authentication is disabled for this project", $response['body']['message']);

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
        $this->assertTrue((new DatetimeValidator())->isValid($response['body']['expire']));

        $userId = $response['body']['userId'];

        $lastEmail = $this->getLastEmail();
        $this->assertEquals($email, $lastEmail['to'][0]['address']);
        $this->assertEquals($this->getProject()['name'] . ' Login', $lastEmail['subject']);
        $this->assertStringContainsStringIgnoringCase('Sign in to '. $this->getProject()['name'] . ' with your secure link. Expires in 1 hour.', $lastEmail['text']);
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
        $this->assertTrue((new DatetimeValidator())->isValid($response['body']['registration']));
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
        $this->assertTrue((new DatetimeValidator())->isValid($response['body']['registration']));
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

    public function testCreatePushTarget(): void
    {
        $response = $this->client->call(Client::METHOD_POST, '/account/targets/push', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), [
            'targetId' => ID::unique(),
            'identifier' => 'test-identifier',
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals('test-identifier', $response['body']['identifier']);
    }

    public function testUpdatePushTarget(): void
    {
        $response = $this->client->call(Client::METHOD_POST, '/account/targets/push', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'targetId' => ID::unique(),
            'identifier' => 'test-identifier-2',
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals('test-identifier-2', $response['body']['identifier']);

        $response = $this->client->call(Client::METHOD_PUT, '/account/targets/'. $response['body']['$id'] .'/push', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'identifier' => 'test-identifier-updated',
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('test-identifier-updated', $response['body']['identifier']);
        $this->assertEquals(false, $response['body']['expired']);
    }

    public function testMFARecoveryCodeChallenge(): void
    {
        // Generate recovery codes using existing authenticated session
        $response = $this->client->call(Client::METHOD_POST, '/account/mfa/recovery-codes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['recoveryCodes']);
        $recoveryCodes = $response['body']['recoveryCodes'];
        $this->assertGreaterThan(0, count($recoveryCodes));

        // Create recovery code challenge
        $challenge = $this->client->call(Client::METHOD_POST, '/account/mfa/challenge', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'factor' => 'recoveryCode'
        ]);

        $this->assertEquals(201, $challenge['headers']['status-code']);
        $this->assertNotEmpty($challenge['body']['$id']);
        $challengeId = $challenge['body']['$id'];

        // Test SUCCESS: Verify with valid recovery code (this tests the bug fix)
        $verification = $this->client->call(Client::METHOD_PUT, '/account/mfa/challenge', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'challengeId' => $challengeId,
            'otp' => $recoveryCodes[0]
        ]);

        $this->assertEquals(200, $verification['headers']['status-code']);
        $this->assertArrayHasKey('factors', $verification['body']);
        $this->assertContains('recoveryCode', $verification['body']['factors']);

        // Test that the code was consumed (can't use again)
        $challenge2 = $this->client->call(Client::METHOD_POST, '/account/mfa/challenge', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'factor' => 'recoveryCode'
        ]);

        $this->assertEquals(201, $challenge2['headers']['status-code']);

        $verification2 = $this->client->call(Client::METHOD_PUT, '/account/mfa/challenge', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'challengeId' => $challenge2['body']['$id'],
            'otp' => $recoveryCodes[0] // Same code should fail
        ]);

        $this->assertEquals(401, $verification2['headers']['status-code']);

        // Test FAILURE: Invalid recovery code
        $challenge3 = $this->client->call(Client::METHOD_POST, '/account/mfa/challenge', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'factor' => 'recoveryCode'
        ]);

        $this->assertEquals(201, $challenge3['headers']['status-code']);

        $verification3 = $this->client->call(Client::METHOD_PUT, '/account/mfa/challenge', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'challengeId' => $challenge3['body']['$id'],
            'otp' => 'invalid-code-123'
        ]);

        $this->assertEquals(401, $verification3['headers']['status-code']);
    }
}
