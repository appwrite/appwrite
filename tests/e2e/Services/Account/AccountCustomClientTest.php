<?php

namespace Tests\E2E\Services\Account;

use Tests\E2E\Client;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\SideClient;
use Utopia\App;

class AccountCustomClientTest extends Scope
{
    use AccountBase;
    use ProjectCustom;
    use SideClient;

    public function testCreateOAuth2AccountSession():array
    {
        $provider = 'mock';
        $appId = '1';
        $secret = '123456';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/projects/'.$this->getProject()['$id'].'/oauth2', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ]), [
            'provider' => $provider,
            'appId' => $appId,
            'secret' => $secret,
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);

        $response = $this->client->call(Client::METHOD_GET, '/account/sessions/oauth2/'.$provider, array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'success' => 'http://localhost/v1/mock/tests/general/oauth2/success',
            'failure' => 'http://localhost/v1/mock/tests/general/oauth2/failure',
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('success', $response['body']['result']);

        return [];
    }

    public function testBlockedAccount():array
    {
        $email = uniqid().'user@localhost.test';
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
            'email' => $email,
            'password' => $password,
            'name' => $name,
        ]);

        $id = $response['body']['$id'];

        $this->assertEquals($response['headers']['status-code'], 201);
    
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

        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $session,
        ]));

        $this->assertEquals($response['headers']['status-code'], 200);

        $response = $this->client->call(Client::METHOD_PATCH, '/users/' . $id . '/status', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'status' => false,
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);

        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $session,
        ]));

        $this->assertEquals($response['headers']['status-code'], 401);

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertEquals($response['headers']['status-code'], 401);

        return [];
    }

    public function testCreateJWT():array
    {
        $email = uniqid().'user@localhost.test';
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
            'email' => $email,
            'password' => $password,
            'name' => $name,
        ]);

        $id = $response['body']['$id'];

        $this->assertEquals($response['headers']['status-code'], 201);
    
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

        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $session,
        ]));

        $this->assertEquals($response['headers']['status-code'], 200);

        $response = $this->client->call(Client::METHOD_POST, '/account/jwt', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $session,
        ]));

        $this->assertEquals($response['headers']['status-code'], 201);
        $this->assertNotEmpty($response['body']['jwt']);
        $this->assertIsString($response['body']['jwt']);

        $jwt = $response['body']['jwt'];

        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-jwt' => 'wrong-token',
        ]));

        $this->assertEquals($response['headers']['status-code'], 401);

        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-jwt' => $jwt,
        ]));

        $this->assertEquals($response['headers']['status-code'], 200);

        $response = $this->client->call(Client::METHOD_DELETE, '/account/sessions/'.$sessionId, array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $session,
        ]));

        $this->assertEquals($response['headers']['status-code'], 204);

        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-jwt' => $jwt,
        ]));

        $this->assertEquals($response['headers']['status-code'], 401);

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
        $this->assertNotEmpty($response['body']['userId']);

        $session = $this->client->parseCookie((string)$response['headers']['set-cookie'])['a_session_'.$this->getProject()['$id']];

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/anonymous', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $session,
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
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $session,
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
        $email = uniqid().'new@localhost.test';

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/account/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $session,
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
        $email = uniqid().'new@localhost.test';
        $password = 'new-password';

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_POST, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => $password
        ]);

        $response = $this->client->call(Client::METHOD_PATCH, '/account/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $session,
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertEquals($response['headers']['status-code'], 400);

        /**
         * Test for SUCCESS
         */
        $email = uniqid().'new@localhost.test';

        $response = $this->client->call(Client::METHOD_PATCH, '/account/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $session,
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertIsArray($response['body']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertIsNumeric($response['body']['registration']);
        $this->assertEquals($response['body']['email'], $email);

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertEquals($response['headers']['status-code'], 201);

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
        $response = $this->client->call(Client::METHOD_PATCH, '/projects/'.$this->getProject()['$id'].'/oauth2', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ]), [
            'provider' => $provider,
            'appId' => $appId,
            'secret' => $secret,
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);

        $response = $this->client->call(Client::METHOD_GET, '/account/sessions/oauth2/'.$provider, array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $session,
        ]), [
            'success' => 'http://localhost/v1/mock/tests/general/oauth2/success',
            'failure' => 'http://localhost/v1/mock/tests/general/oauth2/failure',
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('success', $response['body']['result']);
        
        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $session,
        ]));

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertEquals($response['body']['name'], 'User Name');
        $this->assertEquals($response['body']['email'], 'user@localhost.test');

        return [];
    }

    public function testGetSessionByID() {
        $session = $this->testCreateAnonymousAccount();

        $response = $this->client->call(Client::METHOD_GET, '/account/sessions/current', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $session,
        ]));

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertEquals($response['body']['provider'], 'anonymous');

        $sessionID = $response['body']['$id'];

        $response = $this->client->call(Client::METHOD_GET, '/account/sessions/'.$sessionID, array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $session,
        ]));

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertEquals($response['body']['provider'], 'anonymous');

        $response = $this->client->call(Client::METHOD_GET, '/account/sessions/97823askjdkasd80921371980', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $session,
        ]));

        $this->assertEquals($response['headers']['status-code'], 404);
    }
}