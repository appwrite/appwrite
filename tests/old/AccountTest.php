<?php

namespace Tests\E2E;

use Tests\E2E\Client;

trait TraitDemo {
    function demo2() { var_dump(9876); $this->demo(); }
}

class AccountTest extends Base
{
    use TraitDemo;

    public function demo() {
        var_dump(4321);
    }

    public function testCreateAccount():array
    {
        $email = uniqid().'user@localhost.test';
        $password = 'passwrod';
        $name = 'User Name';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/account', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ], [
            'email' => $email,
            'password' => $password,
            'name' => $name,
        ]);

        $uid = $response['body']['$uid'];

        $this->assertEquals($response['headers']['status-code'], 201);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$uid']);
        $this->assertIsNumeric($response['body']['registration']);
        $this->assertEquals($response['body']['email'], $email);
        $this->assertEquals($response['body']['name'], $name);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_POST, '/account', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ], [
            'email' => $email,
            'password' => $password,
            'name' => $name,
        ]);

        $this->assertEquals($response['headers']['status-code'], 409);

        return [
            'uid' => $uid,
            'email' => $email,
            'password' => $password,
            'name' => $name,
        ];
    }

    /**
     * @depends testCreateAccount
     */
    public function testCreateAccountSession($data):array
    {
        $email = (isset($data['email'])) ? $data['email'] : '';
        $password = (isset($data['password'])) ? $data['password'] : '';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/account/sessions', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ], [
            'email' => $email,
            'password' => $password,
        ]);

        $sessionUid = $response['body']['$uid'];
        $session = $this->client->parseCookie($response['headers']['set-cookie'])['a_session_console'];

        $this->assertEquals($response['headers']['status-code'], 201);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_POST, '/account/sessions', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ], [
            'email' => $email.'x',
            'password' => $password,
        ]);

        $this->assertEquals($response['headers']['status-code'], 401);

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ], [
            'email' => $email,
            'password' => $password.'x',
        ]);

        $this->assertEquals($response['headers']['status-code'], 401);

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ], [
            'email' => '',
            'password' => '',
        ]);

        $this->assertEquals($response['headers']['status-code'], 400);

        return array_merge($data, [
            'sessionUid' => $sessionUid,
            'session' => $session,
        ]);
    }

    /**
     * @depends testCreateAccountSession
     */
    public function testGetAccount($data):array
    {
        $email = (isset($data['email'])) ? $data['email'] : '';
        $name = (isset($data['name'])) ? $data['name'] : '';
        $session = (isset($data['session'])) ? $data['session'] : '';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/account', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $session,
            'x-appwrite-project' => 'console',
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$uid']);
        $this->assertIsNumeric($response['body']['registration']);
        $this->assertEquals($response['body']['email'], $email);
        $this->assertEquals($response['body']['name'], $name);
        $this->assertContains('*', $response['body']['roles']);
        $this->assertContains('user:'.$response['body']['$uid'], $response['body']['roles']);
        $this->assertContains('role:1', $response['body']['roles']);
        $this->assertCount(3, $response['body']['roles']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/account', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ]);

        $this->assertEquals($response['headers']['status-code'], 401);

        $response = $this->client->call(Client::METHOD_GET, '/account', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $session.'xx',
            'x-appwrite-project' => 'console',
        ]);

        $this->assertEquals($response['headers']['status-code'], 401);

        return $data;
    }

    /**
     * @depends testCreateAccountSession
     */
    public function testGetAccountPrefs($data):array
    {
        $session = (isset($data['session'])) ? $data['session'] : '';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/account/prefs', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $session,
            'x-appwrite-project' => 'console',
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertIsArray($response['body']);
        $this->assertEmpty($response['body']);
        $this->assertCount(0, $response['body']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/account/prefs', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ]);

        $this->assertEquals($response['headers']['status-code'], 401);

        return $data;
    }

    /**
     * @depends testCreateAccountSession
     */
    public function testGetAccountSessions($data):array
    {
        $session = (isset($data['session'])) ? $data['session'] : '';
        $sessionUid = (isset($data['sessionUid'])) ? $data['sessionUid'] : '';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/account/sessions', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $session,
            'x-appwrite-project' => 'console',
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertIsArray($response['body']);
        $this->assertNotEmpty($response['body']);
        $this->assertCount(1, $response['body']);
        $this->assertEquals($sessionUid, $response['body'][0]['$uid']);
        
        $this->assertIsArray($response['body'][0]['OS']);
        $this->assertEquals('Windows', $response['body'][0]['OS']['name']);
        $this->assertEquals('WIN', $response['body'][0]['OS']['short_name']);
        $this->assertEquals('10', $response['body'][0]['OS']['version']);
        $this->assertEquals('x64', $response['body'][0]['OS']['platform']);

        $this->assertIsArray($response['body'][0]['client']);
        $this->assertEquals('browser', $response['body'][0]['client']['type']);
        $this->assertEquals('Chrome', $response['body'][0]['client']['name']);
        $this->assertEquals('CH', $response['body'][0]['client']['short_name']); // FIXME (v1) key name should be camelcase
        $this->assertEquals('70.0', $response['body'][0]['client']['version']);
        $this->assertEquals('Blink', $response['body'][0]['client']['engine']);
        $this->assertEquals(0, $response['body'][0]['device']);
        $this->assertEquals('', $response['body'][0]['brand']);
        $this->assertEquals('', $response['body'][0]['model']);
        $this->assertEquals($response['body'][0]['ip'], filter_var($response['body'][0]['ip'], FILTER_VALIDATE_IP));
        
        $this->assertIsArray($response['body'][0]['geo']);
        $this->assertEquals('--', $response['body'][0]['geo']['isoCode']);
        $this->assertEquals('Unknown', $response['body'][0]['geo']['country']);
        
        $this->assertEquals(true, $response['body'][0]['current']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/account/sessions', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ]);

        $this->assertEquals($response['headers']['status-code'], 401);

        return $data;
    }

    /**
     * @depends testCreateAccountSession
     */
    public function testGetAccountLogs($data):array
    {
        sleep(5);
        $session = (isset($data['session'])) ? $data['session'] : '';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/account/logs', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $session,
            'x-appwrite-project' => 'console',
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertIsArray($response['body']);
        $this->assertNotEmpty($response['body']);
        $this->assertCount(2, $response['body']);
        
        $this->assertEquals('account.create', $response['body'][0]['event']);
        $this->assertEquals($response['body'][0]['ip'], filter_var($response['body'][0]['ip'], FILTER_VALIDATE_IP));
        $this->assertIsNumeric($response['body'][0]['time']);

        $this->assertIsArray($response['body'][0]['OS']);
        $this->assertEquals('Windows', $response['body'][0]['OS']['name']);
        $this->assertEquals('WIN', $response['body'][0]['OS']['short_name']);
        $this->assertEquals('10', $response['body'][0]['OS']['version']);
        $this->assertEquals('x64', $response['body'][0]['OS']['platform']);

        $this->assertIsArray($response['body'][0]['client']);
        $this->assertEquals('browser', $response['body'][0]['client']['type']);
        $this->assertEquals('Chrome', $response['body'][0]['client']['name']);
        $this->assertEquals('CH', $response['body'][0]['client']['short_name']); // FIXME (v1) key name should be camelcase
        $this->assertEquals('70.0', $response['body'][0]['client']['version']);
        $this->assertEquals('Blink', $response['body'][0]['client']['engine']);
        $this->assertEquals(0, $response['body'][0]['device']);
        $this->assertEquals('', $response['body'][0]['brand']);
        $this->assertEquals('', $response['body'][0]['model']);
        $this->assertEquals($response['body'][0]['ip'], filter_var($response['body'][0]['ip'], FILTER_VALIDATE_IP));
        
        $this->assertIsArray($response['body'][0]['geo']);
        $this->assertEquals('--', $response['body'][0]['geo']['isoCode']);
        $this->assertEquals('Unknown', $response['body'][0]['geo']['country']);

        $this->assertEquals('account.sessions.create', $response['body'][1]['event']);
        $this->assertEquals($response['body'][1]['ip'], filter_var($response['body'][0]['ip'], FILTER_VALIDATE_IP));
        $this->assertIsNumeric($response['body'][1]['time']);

        $this->assertIsArray($response['body'][1]['OS']);
        $this->assertEquals('Windows', $response['body'][1]['OS']['name']);
        $this->assertEquals('WIN', $response['body'][1]['OS']['short_name']);
        $this->assertEquals('10', $response['body'][1]['OS']['version']);
        $this->assertEquals('x64', $response['body'][1]['OS']['platform']);

        $this->assertIsArray($response['body'][1]['client']);
        $this->assertEquals('browser', $response['body'][1]['client']['type']);
        $this->assertEquals('Chrome', $response['body'][1]['client']['name']);
        $this->assertEquals('CH', $response['body'][1]['client']['short_name']); // FIXME (v1) key name should be camelcase
        $this->assertEquals('70.0', $response['body'][1]['client']['version']);
        $this->assertEquals('Blink', $response['body'][1]['client']['engine']);
        $this->assertEquals(0, $response['body'][1]['device']);
        $this->assertEquals('', $response['body'][1]['brand']);
        $this->assertEquals('', $response['body'][1]['model']);
        $this->assertEquals($response['body'][1]['ip'], filter_var($response['body'][0]['ip'], FILTER_VALIDATE_IP));
        
        $this->assertIsArray($response['body'][1]['geo']);
        $this->assertEquals('--', $response['body'][1]['geo']['isoCode']);
        $this->assertEquals('Unknown', $response['body'][1]['geo']['country']);
        
        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/account/logs', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ]);

        $this->assertEquals($response['headers']['status-code'], 401);

        return $data;
    }

    //TODO Add tests for OAuth session creation

    /**
     * @depends testCreateAccountSession
     */
    public function testUpdateAccountName($data):array
    {
        $email = (isset($data['email'])) ? $data['email'] : '';
        $session = (isset($data['session'])) ? $data['session'] : '';
        $newName = 'New Name';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/account/name', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $session,
            'x-appwrite-project' => 'console',
        ], [
            'name' => $newName
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertIsArray($response['body']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$uid']);
        $this->assertIsNumeric($response['body']['registration']);
        $this->assertEquals($response['body']['email'], $email);
        $this->assertEquals($response['body']['name'], $newName);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/account/name', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ]);

        $this->assertEquals($response['headers']['status-code'], 401);
        
        $response = $this->client->call(Client::METHOD_PATCH, '/account/name', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $session,
            'x-appwrite-project' => 'console',
        ], [
        ]);
        
        $this->assertEquals($response['headers']['status-code'], 400);

        $response = $this->client->call(Client::METHOD_PATCH, '/account/name', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $session,
            'x-appwrite-project' => 'console',
        ], [
            'name' => 'ocSRq1d3QphHivJyUmYY7WMnrxyjdk5YvVwcDqx2zS0coxESN8RmsQwLWw5Whnf0WbVohuFWTRAaoKgCOO0Y0M7LwgFnZmi8881Y7'
        ]);
        
        $this->assertEquals($response['headers']['status-code'], 400);

        $data['name'] = $newName;

        return $data;
    }

    /**
     * @depends testUpdateAccountName
     */
    public function testUpdateAccountPassword($data):array
    {
        $email = (isset($data['email'])) ? $data['email'] : '';
        $password = (isset($data['password'])) ? $data['password'] : '';
        $session = (isset($data['session'])) ? $data['session'] : '';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/account/password', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $session,
            'x-appwrite-project' => 'console',
        ], [
            'password' => 'new-password',
            'old-password' => $password,
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertIsArray($response['body']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$uid']);
        $this->assertIsNumeric($response['body']['registration']);
        $this->assertEquals($response['body']['email'], $email);
        $this->assertEquals($response['body']['name'], 'New Name');

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ], [
            'email' => $email,
            'password' => 'new-password',
        ]);

        $this->assertEquals($response['headers']['status-code'], 201);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/account/password', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ]);

        $this->assertEquals($response['headers']['status-code'], 401);
        
        $response = $this->client->call(Client::METHOD_PATCH, '/account/password', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $session,
            'x-appwrite-project' => 'console',
        ], [
        ]);
        
        $this->assertEquals($response['headers']['status-code'], 400);

        $data['password'] = 'new-password';

        return $data;
    }

    /**
     * @depends testUpdateAccountPassword
     */
    public function testUpdateAccountEmail($data):array
    {
        $newEmail = uniqid().'new@localhost.test';
        $session = (isset($data['session'])) ? $data['session'] : '';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/account/email', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $session,
            'x-appwrite-project' => 'console',
        ], [
            'email' => $newEmail,
            'password' => 'new-password',
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertIsArray($response['body']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$uid']);
        $this->assertIsNumeric($response['body']['registration']);
        $this->assertEquals($response['body']['email'], $newEmail);
        $this->assertEquals($response['body']['name'], 'New Name');

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/account/email', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ]);

        $this->assertEquals($response['headers']['status-code'], 401);
        
        $response = $this->client->call(Client::METHOD_PATCH, '/account/email', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $session,
            'x-appwrite-project' => 'console',
        ], [
        ]);
        
        $this->assertEquals($response['headers']['status-code'], 400);

        $data['email'] = $newEmail;

        return $data;
    }

    /**
     * @depends testUpdateAccountEmail
     */
    public function testUpdateAccountPrefs($data):array
    {
        $newEmail = uniqid().'new@localhost.test';
        $session = (isset($data['session'])) ? $data['session'] : '';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/account/prefs', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $session,
            'x-appwrite-project' => 'console',
        ], [
            'prefs' => [
                'key1' => 'value1',
                'key2' => 'value2',
            ]
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertIsArray($response['body']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']);
        $this->assertEquals('value1', $response['body']['key1']);
        $this->assertEquals('value2', $response['body']['key2']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/account/prefs', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ]);

        $this->assertEquals($response['headers']['status-code'], 401);
        
        return $data;
    }

    /**
     * @depends testUpdateAccountPrefs
     */
    public function testDeleteAccountSession($data):array
    {
        $email = (isset($data['email'])) ? $data['email'] : '';
        $password = (isset($data['password'])) ? $data['password'] : '';
        $session = (isset($data['session'])) ? $data['session'] : '';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/account/sessions', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ], [
            'email' => $email,
            'password' => $password,
        ]);

        $sessionNewUid = $response['body']['$uid'];
        $sessionNew = $this->client->parseCookie($response['headers']['set-cookie'])['a_session_console'];

        $this->assertEquals($response['headers']['status-code'], 201);

        $response = $this->client->call(Client::METHOD_GET, '/account', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $sessionNew,
            'x-appwrite-project' => 'console',
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);

        $response = $this->client->call(Client::METHOD_DELETE, '/account/sessions/'.$sessionNewUid, [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $session,
            'x-appwrite-project' => 'console',
        ]);

        $this->assertEquals($response['headers']['status-code'], 204);

        $response = $this->client->call(Client::METHOD_GET, '/account', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $session,
            'x-appwrite-project' => 'console',
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/account', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $sessionNew,
            'x-appwrite-project' => 'console',
        ]);

        $this->assertEquals($response['headers']['status-code'], 401);

        return $data;
    }

    /**
     * @depends testUpdateAccountPrefs
     */
    public function testDeleteAccountSessionCurrent($data):array
    {
        $email = (isset($data['email'])) ? $data['email'] : '';
        $password = (isset($data['password'])) ? $data['password'] : '';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/account/sessions', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ], [
            'email' => $email,
            'password' => $password,
        ]);

        $sessionNew = $this->client->parseCookie($response['headers']['set-cookie'])['a_session_console'];

        $this->assertEquals($response['headers']['status-code'], 201);

        $response = $this->client->call(Client::METHOD_GET, '/account', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $sessionNew,
            'x-appwrite-project' => 'console',
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);

        $response = $this->client->call(Client::METHOD_DELETE, '/account/sessions/current', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $sessionNew,
            'x-appwrite-project' => 'console',
        ]);

        $this->assertEquals($response['headers']['status-code'], 204);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/account', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $sessionNew,
            'x-appwrite-project' => 'console',
        ]);

        $this->assertEquals($response['headers']['status-code'], 401);

        return $data;
    }

    /**
     * @depends testUpdateAccountPrefs
     */
    public function testDeleteAccountSessions($data):array
    {
        $session = (isset($data['session'])) ? $data['session'] : '';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_DELETE, '/account/sessions', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $session,
            'x-appwrite-project' => 'console',
        ]);

        $this->assertEquals($response['headers']['status-code'], 204);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/account', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $session,
            'x-appwrite-project' => 'console',
        ]);

        $this->assertEquals($response['headers']['status-code'], 401);

        /**
         * Create new fallback session
         */
        $email = (isset($data['email'])) ? $data['email'] : '';
        $password = (isset($data['password'])) ? $data['password'] : '';

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ], [
            'email' => $email,
            'password' => $password,
        ]);

        $data['session'] = $this->client->parseCookie($response['headers']['set-cookie'])['a_session_console'];

        return $data;
    }

    /**
     * @depends testDeleteAccountSession
     */
    public function testCreateAccountRecovery($data):array
    {
        $email = (isset($data['email'])) ? $data['email'] : '';
        $name = (isset($data['name'])) ? $data['name'] : '';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/account/recovery', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ], [
            'email' => $email,
            'reset' => 'http://localhost/recovery',
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty(3, $response['body']['$uid']);
        $this->assertEquals(3, $response['body']['type']);
        $this->assertIsNumeric($response['body']['expire']);
        
        $lastEmail = $this->getLastEmail();

        $this->assertEquals($email, $lastEmail['to'][0]['address']);
        $this->assertEquals($name, $lastEmail['to'][0]['name']);
        $this->assertEquals('Password Reset', $lastEmail['subject']);

        $recovery = substr($lastEmail['text'], strpos($lastEmail['text'], '&token=', 0) + 7, 256);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_POST, '/account/recovery', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ], [
            'email' => $email,
            'reset' => 'localhost/recovery',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/account/recovery', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ], [
            'email' => $email,
            'reset' => 'http://remotehost/recovery',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/account/recovery', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ], [
            'email' => 'not-found@localhost.test',
            'reset' => 'http://localhost/recovery',
        ]);

        $this->assertEquals(404, $response['headers']['status-code']);

        $data['recovery'] = $recovery;

        return $data;
    }

    /**
     * @depends testCreateAccountRecovery
     */
    public function testUpdateAccountRecovery($data):array
    {
        $uid = (isset($data['uid'])) ? $data['uid'] : '';
        $recovery = (isset($data['recovery'])) ? $data['recovery'] : '';
        $newPassowrd = 'test-recovery';
        
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_PUT, '/account/recovery', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ], [
            'userId' => $uid,
            'token' => $recovery,
            'password-a' => $newPassowrd,
            'password-b' => $newPassowrd,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        
        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_PUT, '/account/recovery', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ], [
            'userId' => 'ewewe',
            'token' => $recovery,
            'password-a' => $newPassowrd,
            'password-b' => $newPassowrd,
        ]);

        $this->assertEquals(404, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_PUT, '/account/recovery', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ], [
            'userId' => $uid,
            'token' => 'sdasdasdasd',
            'password-a' => $newPassowrd,
            'password-b' => $newPassowrd,
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_PUT, '/account/recovery', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ], [
            'userId' => $uid,
            'token' => $recovery,
            'password-a' => $newPassowrd.'x',
            'password-b' => $newPassowrd,
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
        
        return $data;
    }
}