<?php

namespace Tests\E2E\Services\Account;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Validator\Datetime as DatetimeValidator;

class AccountCustomServerTest extends Scope
{
    use AccountBase;
    use ProjectCustom;
    use SideServer;

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
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotFalse(\DateTime::createFromFormat('Y-m-d\TH:i:s.uP', $response['body']['expire']));

        $sessionId = $response['body']['$id'];
        $session = $response['body']['secret'];
        $userId = $response['body']['userId'];

        $response = $this->client->call(Client::METHOD_GET, '/users/' . $userId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertArrayHasKey('accessedAt', $response['body']);
        $this->assertNotEmpty($response['body']['accessedAt']);

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['secret']);
        $this->assertNotFalse(\DateTime::createFromFormat('Y-m-d\TH:i:s.uP', $response['body']['expire']));

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-session' => $session,
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'email' => $email . 'x',
            'password' => $password,
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'email' => $email,
            'password' => $password . 'x',
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
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
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-session' => $session,
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

        return $data;
    }

    public function testCreateAnonymousAccount()
    {
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/anonymous', array_merge(
            [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id']
            ],
            $this->getHeaders()
        ));

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertIsArray($response['body']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertNotEmpty($response['body']['secret']);

        \usleep(1000 * 30); // wait for 30ms to let the shutdown update accessedAt

        $userId = $response['body']['userId'];
        $response = $this->client->call(Client::METHOD_GET, '/users/' . $userId, array_merge(
            [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ],
            $this->getHeaders(),
        ));

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertArrayHasKey('accessedAt', $response['body']);

        $this->assertNotEmpty($response['body']['accessedAt']);
    }

    public function testCreateMagicUrl(): array
    {
        $email = \time() . 'user@appwrite.io';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/account/tokens/magic-url', array_merge(
            [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id']
            ],
            $this->getHeaders()
        ), [
            'userId' => ID::unique(),
            'email' => $email,
            // 'url' => 'http://localhost/magiclogin',
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertNotEmpty($response['body']['secret']);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($response['body']['expire']));

        $userId = $response['body']['userId'];

        $lastEmail = $this->getLastEmail();
        $this->assertEquals($email, $lastEmail['to'][0]['address']);
        $this->assertEquals($this->getProject()['name'] . ' Login', $lastEmail['subject']);

        $token = substr($lastEmail['text'], strpos($lastEmail['text'], '&secret=', 0) + 8, 64);

        $expireTime = strpos($lastEmail['text'], 'expire=' . urlencode($response['body']['expire']), 0);

        $this->assertNotFalse($expireTime);

        $secretTest = strpos($lastEmail['text'], 'secret=' . $response['body']['secret'], 0);

        $this->assertNotFalse($secretTest);

        $userIDTest = strpos($lastEmail['text'], 'userId=' . $response['body']['userId'], 0);

        $this->assertNotFalse($userIDTest);

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
        $response = $this->client->call(Client::METHOD_PUT, '/account/sessions/magic-url', array_merge(
            [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id']
            ],
            $this->getHeaders()
        ), [
            'userId' => $id,
            'secret' => $token,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertIsArray($response['body']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertNotEmpty($response['body']['userId']);
        $this->assertNotEmpty($response['body']['secret']);

        $sessionId = $response['body']['$id'];
        $session = $response['body']['secret'];

        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge(
            [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-session' => $session
            ]
        ));

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($response['body']['registration']));
        $this->assertEquals($response['body']['email'], $email);
        $this->assertTrue($response['body']['emailVerification']);

        $data['sessionId'] = $sessionId;
        $data['session'] = $session;

        return $data;
    }
}
