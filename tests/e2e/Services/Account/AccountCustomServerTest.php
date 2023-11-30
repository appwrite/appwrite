<?php

namespace Tests\E2E\Services\Account;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Validator\Datetime as DatetimeValidator;
use Utopia\Database\Helpers\ID;

class AccountCustomServerTest extends Scope
{
    use ProjectCustom;
    use SideServer;


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
        $this->assertEquals('Login', $lastEmail['subject']);

        $token = substr($lastEmail['text'], strpos($lastEmail['text'], '&secret=', 0) + 8, 256);

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
        $session = $this->client->parseCookie((string)$response['headers']['set-cookie'])['a_session_' . $this->getProject()['$id']];

        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge(
            [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
            ],
            $this->getHeaders()
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
