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

        $response = $this->client->call(Client::METHOD_POST, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => '',
            'password' => '',
        ]);

        $this->assertEquals($response['headers']['status-code'], 400);

        $response = $this->client->call(Client::METHOD_POST, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => '',
        ]);

        $this->assertEquals($response['headers']['status-code'], 400);

        $response = $this->client->call(Client::METHOD_POST, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => '',
            'password' => $password,
        ]);

        $this->assertEquals($response['headers']['status-code'], 400);

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
        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';

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
        $session = $this->client->parseCookie((string)$response['headers']['set-cookie'])['a_session_'.$this->getProject()['$id']];

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => $password,
            'duration' => 691200 // 8 days
        ]);

        $timeNow = \time();
        $expectedExpireTime = $timeNow + 691200; // 8 days
        $offset = \abs($response['body']['expire'] - $expectedExpireTime);

        $this->assertEquals($response['headers']['status-code'], 201);
        $this->assertLessThanOrEqual(10, $offset); // Allowed offset is 10 seconds due to HTTP delay

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

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => $password,
            'duration' => 1 // 1 second
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
        $this->assertEquals("Invalid duration: Value must be a valid range between 3,600 and 31,536,000", $response['body']['message']);

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => $password,
            'duration' => 500000000 // Way over limit ...
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
        $this->assertEquals("Invalid duration: Value must be a valid range between 3,600 and 31,536,000", $response['body']['message']);


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
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $session,
        ]));

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertIsNumeric($response['body']['registration']);
        $this->assertEquals($response['body']['email'], $email);
        $this->assertEquals($response['body']['name'], $name);

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
        $session = $data['session'] ?? '';

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
        $session = $data['session'] ?? '';
        $sessionId = $data['sessionId'] ?? '';

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
        $this->assertCount(2, $response['body']);
        $this->assertEquals(2, $response['body']['sum']);
        $this->assertEquals($sessionId, $response['body']['sessions'][0]['$id']);
        
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
        $session = $data['session'] ?? '';

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
        $this->assertIsArray($response['body']['logs']);
        $this->assertNotEmpty($response['body']['logs']);
        $this->assertCount(3, $response['body']['logs']);
        
        $this->assertContains($response['body']['logs'][0]['event'], ['account.create', 'account.sessions.create']);
        $this->assertEquals($response['body']['logs'][0]['ip'], filter_var($response['body']['logs'][0]['ip'], FILTER_VALIDATE_IP));
        $this->assertIsNumeric($response['body']['logs'][0]['time']);

        $this->assertEquals('Windows', $response['body']['logs'][0]['osName']);
        $this->assertEquals('WIN', $response['body']['logs'][0]['osCode']);
        $this->assertEquals('10', $response['body']['logs'][0]['osVersion']);

        $this->assertEquals('browser', $response['body']['logs'][0]['clientType']);
        $this->assertEquals('Chrome', $response['body']['logs'][0]['clientName']);
        $this->assertEquals('CH', $response['body']['logs'][0]['clientCode']);
        $this->assertEquals('70.0', $response['body']['logs'][0]['clientVersion']);
        $this->assertEquals('Blink', $response['body']['logs'][0]['clientEngine']);

        $this->assertEquals('desktop', $response['body']['logs'][0]['deviceName']);
        $this->assertEquals('', $response['body']['logs'][0]['deviceBrand']);
        $this->assertEquals('', $response['body']['logs'][0]['deviceModel']);
        $this->assertEquals($response['body']['logs'][0]['ip'], filter_var($response['body']['logs'][0]['ip'], FILTER_VALIDATE_IP));
        
        $this->assertEquals('--', $response['body']['logs'][0]['countryCode']);
        $this->assertEquals('Unknown', $response['body']['logs'][0]['countryName']);

        $this->assertContains($response['body']['logs'][1]['event'], ['account.create', 'account.sessions.create']);
        $this->assertEquals($response['body']['logs'][1]['ip'], filter_var($response['body']['logs'][1]['ip'], FILTER_VALIDATE_IP));
        $this->assertIsNumeric($response['body']['logs'][1]['time']);

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
        $this->assertEquals($response['body']['logs'][1]['ip'], filter_var($response['body']['logs'][1]['ip'], FILTER_VALIDATE_IP));
        
        $this->assertEquals('--', $response['body']['logs'][1]['countryCode']);
        $this->assertEquals('Unknown', $response['body']['logs'][1]['countryName']);
        
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

    /**
     * @depends testCreateAccountSession
     */
    public function testUpdateAccountName($data):array
    {
        $email = $data['email'] ?? '';
        $session = $data['session'] ?? '';
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
            'name' => 'ocSRq1d3QphHivJyUmYY7WMnrxyjdk5YvVwcDqx2zS0coxESN8RmsQwLWw5Whnf0WbVohuFWTRAaoKgCOO0Y0M7LwgFnZmi8881Y72222222222222222222222222222'
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
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $session,
        ]), [
            'password' => 'new-password',
            'oldPassword' => $password,
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertIsArray($response['body']);
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

        /**
         * Existing user tries to update password by passing wrong old password -> SHOULD FAIL
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
        $this->assertEquals($response['headers']['status-code'], 401);

        /**
         * Existing user tries to update password without passing old password -> SHOULD FAIL 
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/account/password', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $session,
        ]), [
            'password' => 'new-password'
        ]);
        $this->assertEquals($response['headers']['status-code'], 401);

        $data['password'] = 'new-password';

        return $data;
    }

    /**
     * @depends testUpdateAccountPassword
     */
    public function testUpdateAccountEmail($data):array
    {
        $newEmail = uniqid().'new@localhost.test';
        $session = $data['session'] ?? '';

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

        // Test if we can create a new account with the old email

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' =>  $data['email'],
            'password' =>  $data['password'],
            'name' =>  $data['name'],
        ]);

        $this->assertEquals($response['headers']['status-code'], 201);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertIsNumeric($response['body']['registration']);
        $this->assertEquals($response['body']['email'], $data['email']);
        $this->assertEquals($response['body']['name'], $data['name'],);
        

        $data['email'] = $newEmail;

        return $data;
    }

    /**
     * @depends testUpdateAccountEmail
     */
    public function testUpdateAccountPrefs($data):array
    {
        $newEmail = uniqid().'new@localhost.test';
        $session = $data['session'] ?? '';

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
                'prefKey1' => 'prefValue1',
                'prefKey2' => 'prefValue2',
            ]
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
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
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $session,
            
        ]), [
            'url' => 'http://localhost/verification',
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEmpty($response['body']['secret']);
        $this->assertIsNumeric($response['body']['expire']);

        $lastEmail = $this->getLastEmail();

        $this->assertEquals($email, $lastEmail['to'][0]['address']);
        $this->assertEquals($name, $lastEmail['to'][0]['name']);
        $this->assertEquals('Account Verification', $lastEmail['subject']);

        $verification = substr($lastEmail['text'], strpos($lastEmail['text'], '&secret=', 0) + 8, 256);

        $expireTime = strpos($lastEmail['text'], 'expire='.$response['body']['expire'], 0);

        $this->assertNotFalse($expireTime);
        
        $secretTest = strpos($lastEmail['text'], 'secret='.$response['body']['secret'], 0);

        $this->assertNotFalse($secretTest);

        $userIDTest = strpos($lastEmail['text'], 'userId='.$response['body']['userId'], 0);

        $this->assertNotFalse($userIDTest);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_POST, '/account/verification', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $session,
        ]), [
            'url' => 'localhost/verification',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/account/verification', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $session,
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
    public function testUpdateAccountVerification($data):array
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
        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';
        $session = $data['session'] ?? '';

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
        $sessionNew = $this->client->parseCookie((string)$response['headers']['set-cookie'])['a_session_'.$this->getProject()['$id']];

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
        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';

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

        $sessionNew = $this->client->parseCookie((string)$response['headers']['set-cookie'])['a_session_'.$this->getProject()['$id']];

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
        $session = $data['session'] ?? '';

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
        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $data['session'] = $this->client->parseCookie((string)$response['headers']['set-cookie'])['a_session_'.$this->getProject()['$id']];

        return $data;
    }

    /**
     * @depends testDeleteAccountSession
     */
    public function testCreateAccountRecovery($data):array
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
        $this->assertIsNumeric($response['body']['expire']);

        $lastEmail = $this->getLastEmail();

        $this->assertEquals($email, $lastEmail['to'][0]['address']);
        $this->assertEquals($name, $lastEmail['to'][0]['name']);
        $this->assertEquals('Password Reset', $lastEmail['subject']);

        $recovery = substr($lastEmail['text'], strpos($lastEmail['text'], '&secret=', 0) + 8, 256);

        $expireTime = strpos($lastEmail['text'], 'expire='.$response['body']['expire'], 0);

        $this->assertNotFalse($expireTime);
        
        $secretTest = strpos($lastEmail['text'], 'secret='.$response['body']['secret'], 0);

        $this->assertNotFalse($secretTest);

        $userIDTest = strpos($lastEmail['text'], 'userId='.$response['body']['userId'], 0);

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
    public function testUpdateAccountRecovery($data):array
    {
        $id = $data['id'] ?? '';
        $recovery = $data['recovery'] ?? '';
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

    public function testCreateMagicUrl():array
    {
        $email = \time().'user@appwrite.io';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/magic-url', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            // 'url' => 'http://localhost/magiclogin',
            'duration' => 2073600 // 24 days
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEmpty($response['body']['secret']);
        $this->assertIsNumeric($response['body']['expire']);

        $userId = $response['body']['userId'];

        $lastEmail = $this->getLastEmail();
        $this->assertEquals($email, $lastEmail['to'][0]['address']);
        $this->assertEquals('Login', $lastEmail['subject']);

        $token = substr($lastEmail['text'], strpos($lastEmail['text'], '&secret=', 0) + 8, 256);

        $expireTime = strpos($lastEmail['text'], 'expire='.$response['body']['expire'], 0);

        $this->assertNotFalse($expireTime);

        $secretTest = strpos($lastEmail['text'], 'secret='.$response['body']['secret'], 0);

        $this->assertNotFalse($secretTest);

        $userIDTest = strpos($lastEmail['text'], 'userId='.$response['body']['userId'], 0);

        $this->assertNotFalse($userIDTest);

        $lastEmail = $this->getLastEmail();
        $this->assertEquals($email, $lastEmail['to'][0]['address']);
        $this->assertEquals('Login', $lastEmail['subject']);
        $expireTime = strpos($lastEmail['text'], 'expire='.$response['body']['expire'], 0);

        $this->assertNotFalse($expireTime);

        $secretTest = strpos($lastEmail['text'], 'secret='.$response['body']['secret'], 0);

        $this->assertNotFalse($secretTest);

        $userIDTest = strpos($lastEmail['text'], 'userId='.$response['body']['userId'], 0);

        $this->assertNotFalse($userIDTest);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/magic-url', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'url' => 'localhost/magiclogin',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/magic-url', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'url' => 'http://remotehost/magiclogin',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/magic-url', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            // 'url' => 'http://localhost/magiclogin',
            'duration' => 1 // 1 second
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
        $this->assertEquals("Invalid duration: Value must be a valid range between 3,600 and 31,536,000", $response['body']['message']);

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/magic-url', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            // 'url' => 'http://localhost/magiclogin',
            'duration' => 500000000 // Way over limit ...
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
        $this->assertEquals("Invalid duration: Value must be a valid range between 3,600 and 31,536,000", $response['body']['message']);

        $data['token'] = $token;
        $data['id'] = $userId;

        return $data;
    }

    /**
     * @depends testCreateMagicUrl
     */
    public function testCreateSessionWithMagicUrl($data):array
    {
        $id = $data['id'] ?? '';
        $token = $data['token'] ?? '';
        
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

        $timeNow = \time();
        $expectedExpireTime = $timeNow + 2073600; // 24 days
        $offset = \abs($response['body']['expire'] - $expectedExpireTime);
        $this->assertLessThanOrEqual(10, $offset); // Allowed offset is 10 seconds due to HTTP delay


        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_PUT, '/account/sessions/magic-url', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => 'ewewe',
            'secret' => $token,
        ]);

        $this->assertEquals(404, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_PUT, '/account/sessions/magic-url', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => $id,
            'secret' => 'sdasdasdasd',
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);
        
        return $data;
    }
}