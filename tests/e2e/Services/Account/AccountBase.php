<?php

namespace Tests\E2E\Services\Account;

use Tests\E2E\Client;

trait AccountBase
{
    public function testCreateAccount():array
    {
        $email = uniqid().'user@localhost.test';
        $password = 'password';
        $name = 'User Name';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => $password,
            'name' => $name,
        ]);

        $id = $response['body']['$id'];

        $this->assertEquals($response['headers']['status-code'], 201);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertIsNumeric($response['body']['registration']);
        $this->assertEquals($response['body']['email'], $email);
        $this->assertEquals($response['body']['name'], $name);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_POST, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => $password,
            'name' => $name,
        ]);

        $this->assertEquals($response['headers']['status-code'], 409);

        sleep(5);

        return [
            'id' => $id,
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
        $response = $this->client->call(Client::METHOD_POST, '/account/sessions', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertEquals($response['headers']['status-code'], 201);

        $sessionId = $response['body']['$id'];
        $session = $this->client->parseCookie($response['headers']['set-cookie'])['a_session_'.$this->getProject()['$id']];

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_POST, '/account/sessions', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email.'x',
            'password' => $password,
        ]);

        $this->assertEquals($response['headers']['status-code'], 401);

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => $password.'x',
        ]);

        $this->assertEquals($response['headers']['status-code'], 401);

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => '',
            'password' => '',
        ]);

        $this->assertEquals($response['headers']['status-code'], 400);

        return array_merge($data, [
            'sessionId' => $sessionId,
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
        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $session,
        ]));

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertIsNumeric($response['body']['registration']);
        $this->assertEquals($response['body']['email'], $email);
        $this->assertEquals($response['body']['name'], $name);
        $this->assertContains('*', $response['body']['roles']);
        $this->assertContains('user:'.$response['body']['$id'], $response['body']['roles']);
        $this->assertContains('role:1', $response['body']['roles']);
        $this->assertCount(3, $response['body']['roles']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]));

        $this->assertEquals($response['headers']['status-code'], 401);

        $response = $this->client->call(Client::METHOD_GET, '/account', [
            'content-type' => 'application/json',
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $session.'xx',
            'x-appwrite-project' => $this->getProject()['$id'],
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
        $response = $this->client->call(Client::METHOD_GET, '/account/prefs', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $session,
        ]));

        $this->assertEquals($response['headers']['status-code'], 200);
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

        $this->assertEquals($response['headers']['status-code'], 401);

        return $data;
    }

    /**
     * @depends testCreateAccountSession
     */
    public function testGetAccountSessions($data):array
    {
        $session = (isset($data['session'])) ? $data['session'] : '';
        $sessionId = (isset($data['sessionId'])) ? $data['sessionId'] : '';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/account/sessions', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $session,
        ]));

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertIsArray($response['body']);
        $this->assertNotEmpty($response['body']);
        $this->assertCount(1, $response['body']);
        $this->assertEquals($sessionId, $response['body'][0]['$id']);
        
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
        $response = $this->client->call(Client::METHOD_GET, '/account/sessions', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]));

        $this->assertEquals($response['headers']['status-code'], 401);

        return $data;
    }

    /**
     * @depends testCreateAccountSession
     */
    public function testGetAccountLogs($data):array
    {
        sleep(10);
        $session = (isset($data['session'])) ? $data['session'] : '';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/account/logs', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $session,
        ]));

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertIsArray($response['body']);
        $this->assertNotEmpty($response['body']);
        $this->assertCount(2, $response['body']);
        
        $this->assertEquals('account.sessions.create', $response['body'][0]['event']);
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

        $this->assertEquals('account.create', $response['body'][1]['event']);
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
        $response = $this->client->call(Client::METHOD_GET, '/account/logs', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]));

        $this->assertEquals($response['headers']['status-code'], 401);

        return $data;
    }

    //TODO Add tests for OAuth2 session creation

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
        $response = $this->client->call(Client::METHOD_PATCH, '/account/name', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $session,
        ]), [
            'name' => $newName
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertIsArray($response['body']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertIsNumeric($response['body']['registration']);
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

        $this->assertEquals($response['headers']['status-code'], 401);
        
        $response = $this->client->call(Client::METHOD_PATCH, '/account/name', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $session,
        ]), [
        ]);
        
        $this->assertEquals($response['headers']['status-code'], 400);

        $response = $this->client->call(Client::METHOD_PATCH, '/account/name', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $session,
        ]), [
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
        $response = $this->client->call(Client::METHOD_PATCH, '/account/password', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $session,
        ]), [
            'password' => 'new-password',
            'oldPassword' => $password,
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertIsArray($response['body']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertIsNumeric($response['body']['registration']);
        $this->assertEquals($response['body']['email'], $email);
        $this->assertEquals($response['body']['name'], 'New Name');

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => 'new-password',
        ]);

        $this->assertEquals($response['headers']['status-code'], 201);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/account/password', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]));

        $this->assertEquals($response['headers']['status-code'], 401);
        
        $response = $this->client->call(Client::METHOD_PATCH, '/account/password', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $session,
        ]), [
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
        $response = $this->client->call(Client::METHOD_PATCH, '/account/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $session,
        ]), [
            'email' => $newEmail,
            'password' => 'new-password',
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertIsArray($response['body']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertIsNumeric($response['body']['registration']);
        $this->assertEquals($response['body']['email'], $newEmail);
        $this->assertEquals($response['body']['name'], 'New Name');

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/account/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]));

        $this->assertEquals($response['headers']['status-code'], 401);
        
        $response = $this->client->call(Client::METHOD_PATCH, '/account/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $session,
        ]), [
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
        $response = $this->client->call(Client::METHOD_PATCH, '/account/prefs', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $session,
        ]), [
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
        $response = $this->client->call(Client::METHOD_PATCH, '/account/prefs', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]));

        $this->assertEquals($response['headers']['status-code'], 401);
        
        $response = $this->client->call(Client::METHOD_PATCH, '/account/prefs', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $session,
        ]), [
            'prefs' => '{}'
        ]);

        $this->assertEquals($response['headers']['status-code'], 400);
        
        
        $response = $this->client->call(Client::METHOD_PATCH, '/account/prefs', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $session,
        ]), [
            'prefs' => '[]'
        ]);

        $this->assertEquals($response['headers']['status-code'], 400);
        
        $response = $this->client->call(Client::METHOD_PATCH, '/account/prefs', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $session,
        ]), [
            'prefs' => '{"test": "value"}'
        ]);

        $this->assertEquals($response['headers']['status-code'], 400);

        return $data;
    }

    /**
     * @depends testUpdateAccountPrefs
     */
    public function testCreateAccountVerification($data):array
    {
        $email = (isset($data['email'])) ? $data['email'] : '';
        $name = (isset($data['name'])) ? $data['name'] : '';
        $session = (isset($data['session'])) ? $data['session'] : '';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/account/verification', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $session,
            
        ]), [
            'url' => 'http://localhost/verification',
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals(2, $response['body']['type']);
        $this->assertIsNumeric($response['body']['expire']);
        
        $lastEmail = $this->getLastEmail();

        $this->assertEquals($email, $lastEmail['to'][0]['address']);
        $this->assertEquals($name, $lastEmail['to'][0]['name']);
        $this->assertEquals('Account Verification', $lastEmail['subject']);

        $verification = substr($lastEmail['text'], strpos($lastEmail['text'], '&secret=', 0) + 8, 256);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_POST, '/account/recovery', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $session,
        ]), [
            'url' => 'localhost/recovery',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/account/recovery', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $session,
        ]), [
            'url' => 'http://remotehost/recovery',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $data['verification'] = $verification;

        return $data;
    }

    /**
     * @depends testCreateAccountVerification
     */
    public function testUpdateAccountVerification($data):array
    {
        $id = (isset($data['id'])) ? $data['id'] : '';
        $session = (isset($data['session'])) ? $data['session'] : '';
        $verification = (isset($data['verification'])) ? $data['verification'] : '';
        
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_PUT, '/account/verification', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $session,
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
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $session,
        ]), [
            'userId' => 'ewewe',
            'secret' => $verification,
        ]);

        $this->assertEquals(404, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_PUT, '/account/verification', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $session,
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
    public function testDeleteAccountSession($data):array
    {
        $email = (isset($data['email'])) ? $data['email'] : '';
        $password = (isset($data['password'])) ? $data['password'] : '';
        $session = (isset($data['session'])) ? $data['session'] : '';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/account/sessions', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $sessionNewId = $response['body']['$id'];
        $sessionNew = $this->client->parseCookie($response['headers']['set-cookie'])['a_session_'.$this->getProject()['$id']];

        $this->assertEquals($response['headers']['status-code'], 201);

        $response = $this->client->call(Client::METHOD_GET, '/account', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $sessionNew,
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);

        $response = $this->client->call(Client::METHOD_DELETE, '/account/sessions/'.$sessionNewId, array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $sessionNew,
        ]));

        $this->assertEquals($response['headers']['status-code'], 204);

        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $session,
        ]));

        $this->assertEquals($response['headers']['status-code'], 200);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/account', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $sessionNew,
        ]);

        $this->assertEquals($response['headers']['status-code'], 401);

        return $data;
    }

    /**
     * @depends testUpdateAccountVerification
     */
    public function testDeleteAccountSessionCurrent($data):array
    {
        $email = (isset($data['email'])) ? $data['email'] : '';
        $password = (isset($data['password'])) ? $data['password'] : '';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/account/sessions', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $sessionNew = $this->client->parseCookie($response['headers']['set-cookie'])['a_session_'.$this->getProject()['$id']];

        $this->assertEquals($response['headers']['status-code'], 201);

        $response = $this->client->call(Client::METHOD_GET, '/account', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $sessionNew,
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);

        $response = $this->client->call(Client::METHOD_DELETE, '/account/sessions/current', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $sessionNew,
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        $this->assertEquals($response['headers']['status-code'], 204);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/account', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $sessionNew,
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        $this->assertEquals($response['headers']['status-code'], 401);

        return $data;
    }

    /**
     * @depends testUpdateAccountVerification
     */
    public function testDeleteAccountSessions($data):array
    {
        $session = (isset($data['session'])) ? $data['session'] : '';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_DELETE, '/account/sessions', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $session,
        ]));

        $this->assertEquals($response['headers']['status-code'], 204);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]));

        $this->assertEquals($response['headers']['status-code'], 401);

        /**
         * Create new fallback session
         */
        $email = (isset($data['email'])) ? $data['email'] : '';
        $password = (isset($data['password'])) ? $data['password'] : '';

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $data['session'] = $this->client->parseCookie($response['headers']['set-cookie'])['a_session_'.$this->getProject()['$id']];

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
        $this->assertEquals(3, $response['body']['type']);
        $this->assertIsNumeric($response['body']['expire']);
        
        $lastEmail = $this->getLastEmail();

        $this->assertEquals($email, $lastEmail['to'][0]['address']);
        $this->assertEquals($name, $lastEmail['to'][0]['name']);
        $this->assertEquals('Password Reset', $lastEmail['subject']);

        $recovery = substr($lastEmail['text'], strpos($lastEmail['text'], '&secret=', 0) + 8, 256);

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
    public function testUpdateAccountRecovery($data):array
    {
        $id = (isset($data['id'])) ? $data['id'] : '';
        $recovery = (isset($data['recovery'])) ? $data['recovery'] : '';
        $newPassowrd = 'test-recovery';
        
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
            'password' => $newPassowrd,
            'passwordAgain' => $newPassowrd,
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
            'userId' => 'ewewe',
            'secret' => $recovery,
            'password' => $newPassowrd,
            'passwordAgain' => $newPassowrd,
        ]);

        $this->assertEquals(404, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_PUT, '/account/recovery', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => $id,
            'secret' => 'sdasdasdasd',
            'password' => $newPassowrd,
            'passwordAgain' => $newPassowrd,
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_PUT, '/account/recovery', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => $id,
            'secret' => $recovery,
            'password' => $newPassowrd.'x',
            'passwordAgain' => $newPassowrd,
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
        
        return $data;
    }
}