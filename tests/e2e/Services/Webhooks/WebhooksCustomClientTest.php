<?php

namespace Tests\E2E\Services\Webhooks;

use Tests\E2E\Client;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\SideClient;

class WebhooksCustomClientTest extends Scope
{
    use WebhooksBase;
    use ProjectCustom;
    use SideClient;

    public function testCreateAccount():array
    {
        $email = uniqid().'user@localhost.test';
        $password = 'password';
        $name = 'User Name';

        /**
         * Test for SUCCESS
         */
        $account = $this->client->call(Client::METHOD_POST, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => $password,
            'name' => $name,
        ]);

        $id = $account['body']['$id'];

        $this->assertEquals($account['headers']['status-code'], 201);
        $this->assertNotEmpty($account['body']);

        $webhook = $this->getLastRequest();

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Event'], 'account.create');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], 'not-yet-implemented');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-User-Id']), true);
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertEquals($webhook['data']['name'], $name);
        $this->assertIsInt($webhook['data']['registration']);
        $this->assertEquals($webhook['data']['status'], 0);
        $this->assertEquals($webhook['data']['email'], $email);
        $this->assertEquals($webhook['data']['emailVerification'], false);
        $this->assertEquals($webhook['data']['prefs'], []);

        return [
            'id' => $id,
            'email' => $email,
            'password' => $password,
            'name' => $name,
        ];
    }

    public function testDeleteAccount():array
    {
        $email = uniqid().'user1@localhost.test';
        $password = 'password';
        $name = 'User Name 1';

        /**
         * Test for SUCCESS
         */
        $account = $this->client->call(Client::METHOD_POST, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => $password,
            'name' => $name,
        ]);

        $accountSession = $this->client->call(Client::METHOD_POST, '/account/sessions', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertEquals($accountSession['headers']['status-code'], 201);

        $sessionId = $accountSession['body']['$id'];
        $session = $this->client->parseCookie((string)$accountSession['headers']['set-cookie'])['aw'.$this->getProject()['$id']];

        $account = $this->client->call(Client::METHOD_DELETE, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'aw'.$this->getProject()['$id'].'=' . $session,
        ]));

        $this->assertEquals($account['headers']['status-code'], 204);
        $this->assertEmpty($account['body']);

        $webhook = $this->getLastRequest();

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Event'], 'account.delete');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], 'not-yet-implemented');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-User-Id'] ?? ''), ('server' === $this->getSide()));
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertEquals($webhook['data']['name'], $name);
        $this->assertIsInt($webhook['data']['registration']);
        $this->assertEquals($webhook['data']['status'], 2);
        $this->assertEquals($webhook['data']['email'], $email);
        $this->assertEquals($webhook['data']['emailVerification'], false);
        $this->assertEquals($webhook['data']['prefs'], []);

        return [];
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
        $accountSession = $this->client->call(Client::METHOD_POST, '/account/sessions', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertEquals($accountSession['headers']['status-code'], 201);

        $sessionId = $accountSession['body']['$id'];
        $session = $this->client->parseCookie((string)$accountSession['headers']['set-cookie'])['aw'.$this->getProject()['$id']];

        $webhook = $this->getLastRequest();

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Event'], 'account.sessions.create');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], 'not-yet-implemented');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-User-Id']), true);
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertNotEmpty($webhook['data']['userId']);
        $this->assertIsInt($webhook['data']['expire']);
        $this->assertEquals($webhook['data']['ip'], '127.0.0.1');
        $this->assertNotEmpty($webhook['data']['osCode']);
        $this->assertIsString($webhook['data']['osCode']);
        $this->assertNotEmpty($webhook['data']['osName']);
        $this->assertIsString($webhook['data']['osName']);
        $this->assertNotEmpty($webhook['data']['osVersion']);
        $this->assertIsString($webhook['data']['osVersion']);
        $this->assertEquals($webhook['data']['clientType'], 'browser');
        $this->assertEquals($webhook['data']['clientCode'], 'CH');
        $this->assertEquals($webhook['data']['clientName'], 'Chrome');
        $this->assertNotEmpty($webhook['data']['clientVersion']);
        $this->assertIsString($webhook['data']['clientVersion']);
        $this->assertNotEmpty($webhook['data']['clientEngine']);
        $this->assertIsString($webhook['data']['clientEngine']);
        $this->assertIsString($webhook['data']['clientEngineVersion']);
        $this->assertIsString($webhook['data']['deviceName']);
        $this->assertIsString($webhook['data']['deviceBrand']);
        $this->assertIsString($webhook['data']['deviceModel']);
        $this->assertIsString($webhook['data']['countryCode']);
        $this->assertIsString($webhook['data']['countryName']);
        $this->assertEquals($webhook['data']['current'], true);

        return array_merge($data, [
            'sessionId' => $sessionId,
            'session' => $session,
        ]);
    }

    /**
     * @depends testCreateAccount
     */
    public function testDeleteAccountSession($data):array
    {
        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';

        /**
         * Test for SUCCESS
         */
        $accountSession = $this->client->call(Client::METHOD_POST, '/account/sessions', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $sessionId = $accountSession['body']['$id'];
        $session = $this->client->parseCookie((string)$accountSession['headers']['set-cookie'])['aw'.$this->getProject()['$id']];

        $this->assertEquals($accountSession['headers']['status-code'], 201);

        $accountSession = $this->client->call(Client::METHOD_DELETE, '/account/sessions/'.$sessionId, array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'aw'.$this->getProject()['$id'].'=' . $session,
        ]));

        $this->assertEquals($accountSession['headers']['status-code'], 204);

        $webhook = $this->getLastRequest();

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Event'], 'account.sessions.delete');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], 'not-yet-implemented');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-User-Id'] ?? ''), ('server' === $this->getSide()));
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertNotEmpty($webhook['data']['userId']);
        $this->assertIsInt($webhook['data']['expire']);
        $this->assertEquals($webhook['data']['ip'], '127.0.0.1');
        $this->assertNotEmpty($webhook['data']['osCode']);
        $this->assertIsString($webhook['data']['osCode']);
        $this->assertNotEmpty($webhook['data']['osName']);
        $this->assertIsString($webhook['data']['osName']);
        $this->assertNotEmpty($webhook['data']['osVersion']);
        $this->assertIsString($webhook['data']['osVersion']);
        $this->assertEquals($webhook['data']['clientType'], 'browser');
        $this->assertEquals($webhook['data']['clientCode'], 'CH');
        $this->assertEquals($webhook['data']['clientName'], 'Chrome');
        $this->assertNotEmpty($webhook['data']['clientVersion']);
        $this->assertIsString($webhook['data']['clientVersion']);
        $this->assertNotEmpty($webhook['data']['clientEngine']);
        $this->assertIsString($webhook['data']['clientEngine']);
        $this->assertIsString($webhook['data']['clientEngineVersion']);
        $this->assertIsString($webhook['data']['deviceName']);
        $this->assertIsString($webhook['data']['deviceBrand']);
        $this->assertIsString($webhook['data']['deviceModel']);
        $this->assertIsString($webhook['data']['countryCode']);
        $this->assertIsString($webhook['data']['countryName']);
        $this->assertEquals($webhook['data']['current'], true);

        return $data;
    }

    /**
     * @depends testCreateAccount
     */
    public function testDeleteAccountSessions($data):array
    {
        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';

        /**
         * Test for SUCCESS
         */
        $accountSession = $this->client->call(Client::METHOD_POST, '/account/sessions', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $session = $this->client->parseCookie((string)$accountSession['headers']['set-cookie'])['aw'.$this->getProject()['$id']];

        $this->assertEquals($accountSession['headers']['status-code'], 201);

        $accountSession = $this->client->call(Client::METHOD_DELETE, '/account/sessions', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'aw'.$this->getProject()['$id'].'=' . $session,
        ]));

        $this->assertEquals($accountSession['headers']['status-code'], 204);

        $webhook = $this->getLastRequest();

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Event'], 'account.sessions.delete');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], 'not-yet-implemented');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-User-Id'] ?? ''), ('server' === $this->getSide()));
        $this->assertEquals($webhook['data']['sum'], 2);
        $this->assertNotEmpty($webhook['data']['sessions'][1]['$id']);
        $this->assertNotEmpty($webhook['data']['sessions'][1]['userId']);
        $this->assertIsInt($webhook['data']['sessions'][1]['expire']);
        $this->assertEquals($webhook['data']['sessions'][1]['ip'], '127.0.0.1');
        $this->assertNotEmpty($webhook['data']['sessions'][1]['osCode']);
        $this->assertIsString($webhook['data']['sessions'][1]['osCode']);
        $this->assertNotEmpty($webhook['data']['sessions'][1]['osName']);
        $this->assertIsString($webhook['data']['sessions'][1]['osName']);
        $this->assertNotEmpty($webhook['data']['sessions'][1]['osVersion']);
        $this->assertIsString($webhook['data']['sessions'][1]['osVersion']);
        $this->assertEquals($webhook['data']['sessions'][1]['clientType'], 'browser');
        $this->assertEquals($webhook['data']['sessions'][1]['clientCode'], 'CH');
        $this->assertEquals($webhook['data']['sessions'][1]['clientName'], 'Chrome');
        $this->assertNotEmpty($webhook['data']['sessions'][1]['clientVersion']);
        $this->assertIsString($webhook['data']['sessions'][1]['clientVersion']);
        $this->assertNotEmpty($webhook['data']['sessions'][1]['clientEngine']);
        $this->assertIsString($webhook['data']['sessions'][1]['clientEngine']);
        $this->assertIsString($webhook['data']['sessions'][1]['clientEngineVersion']);
        $this->assertIsString($webhook['data']['sessions'][1]['deviceName']);
        $this->assertIsString($webhook['data']['sessions'][1]['deviceBrand']);
        $this->assertIsString($webhook['data']['sessions'][1]['deviceModel']);
        $this->assertIsString($webhook['data']['sessions'][1]['countryCode']);
        $this->assertIsString($webhook['data']['sessions'][1]['countryName']);
        $this->assertEquals($webhook['data']['sessions'][1]['current'], true);

        $accountSession = $this->client->call(Client::METHOD_POST, '/account/sessions', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertEquals($accountSession['headers']['status-code'], 201);

        $sessionId = $accountSession['body']['$id'];
        $session = $this->client->parseCookie((string)$accountSession['headers']['set-cookie'])['aw'.$this->getProject()['$id']];

        return array_merge($data, [
            'sessionId' => $sessionId,
            'session' => $session,
        ]);
    }

    /**
     * @depends testDeleteAccountSessions
     */
    public function testUpdateAccountName($data): array
    {
        $id = $data['id'] ?? '';
        $email = $data['email'] ?? '';
        $session = $data['session'] ?? '';
        $newName = 'New Name';

        $account = $this->client->call(Client::METHOD_PATCH, '/account/name', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'aw'.$this->getProject()['$id'].'=' . $session,
        ]), [
            'name' => $newName
        ]);

        $this->assertEquals($account['headers']['status-code'], 200);
        $this->assertIsArray($account['body']);

        $webhook = $this->getLastRequest();

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Event'], 'account.update.name');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], 'not-yet-implemented');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-User-Id'] ?? ''), ('server' === $this->getSide()));
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertEquals($webhook['data']['name'], $newName);
        $this->assertIsInt($webhook['data']['registration']);
        $this->assertEquals($webhook['data']['status'], 0);
        $this->assertEquals($webhook['data']['email'], $email);
        $this->assertEquals($webhook['data']['emailVerification'], false);
        $this->assertEquals($webhook['data']['prefs'], []);

        return $data;
    }

    /**
     * @depends testUpdateAccountName
     */
    public function testUpdateAccountPassword($data): array
    {
        $id = $data['id'] ?? '';
        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';
        $session = $data['session'] ?? '';

        $account = $this->client->call(Client::METHOD_PATCH, '/account/password', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'aw'.$this->getProject()['$id'].'=' . $session,
        ]), [
            'password' => 'new-password',
            'oldPassword' => $password,
        ]);

        $this->assertEquals($account['headers']['status-code'], 200);
        $this->assertIsArray($account['body']);

        $webhook = $this->getLastRequest();

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Event'], 'account.update.password');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], 'not-yet-implemented');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-User-Id'] ?? ''), ('server' === $this->getSide()));
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertEquals($webhook['data']['name'], 'New Name');
        $this->assertIsInt($webhook['data']['registration']);
        $this->assertEquals($webhook['data']['status'], 0);
        $this->assertEquals($webhook['data']['email'], $email);
        $this->assertEquals($webhook['data']['emailVerification'], false);
        $this->assertEquals($webhook['data']['prefs'], []);

        $data['password'] = 'new-password';

        return $data;
    }

    /**
     * @depends testUpdateAccountPassword
     */
    public function testUpdateAccountEmail($data): array
    {
        $id = $data['id'] ?? '';
        $email = $data['email'] ?? '';
        $newEmail = uniqid().'new@localhost.test';
        $session = $data['session'] ?? '';

        $account = $this->client->call(Client::METHOD_PATCH, '/account/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'aw'.$this->getProject()['$id'].'=' . $session,
        ]), [
            'email' => $newEmail,
            'password' => 'new-password',
        ]);

        $this->assertEquals($account['headers']['status-code'], 200);
        $this->assertIsArray($account['body']);

        $webhook = $this->getLastRequest();

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Event'], 'account.update.email');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], 'not-yet-implemented');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-User-Id'] ?? ''), ('server' === $this->getSide()));
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertEquals($webhook['data']['name'], 'New Name');
        $this->assertIsInt($webhook['data']['registration']);
        $this->assertEquals($webhook['data']['status'], 0);
        $this->assertEquals($webhook['data']['email'], $newEmail);
        $this->assertEquals($webhook['data']['emailVerification'], false);
        $this->assertEquals($webhook['data']['prefs'], []);

        $data['email'] = $newEmail;

        return $data;
    }

    /**
     * @depends testUpdateAccountEmail
     */
    public function testUpdateAccountPrefs($data): array
    {
        $id = $data['id'] ?? '';
        $email = $data['email'] ?? '';
        $session = $data['session'] ?? '';

        $account = $this->client->call(Client::METHOD_PATCH, '/account/prefs', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'aw'.$this->getProject()['$id'].'=' . $session,
        ]), [
            'prefs' => [
                'prefKey1' => 'prefValue1',
                'prefKey2' => 'prefValue2',
            ]
        ]);

        $this->assertEquals($account['headers']['status-code'], 200);
        $this->assertIsArray($account['body']);

        $webhook = $this->getLastRequest();

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Event'], 'account.update.prefs');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], 'not-yet-implemented');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-User-Id'] ?? ''), ('server' === $this->getSide()));
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertEquals($webhook['data']['name'], 'New Name');
        $this->assertIsInt($webhook['data']['registration']);
        $this->assertEquals($webhook['data']['status'], 0);
        $this->assertEquals($webhook['data']['email'], $email);
        $this->assertEquals($webhook['data']['emailVerification'], false);
        $this->assertEquals($webhook['data']['prefs'], [
            'prefKey1' => 'prefValue1',
            'prefKey2' => 'prefValue2',
        ]);

        return $data;
    }

    /**
     * @depends testUpdateAccountPrefs
     */
    public function testCreateAccountRecovery($data): array
    {
        $id = $data['id'] ?? '';
        $email = $data['email'] ?? '';
        $session = $data['session'] ?? '';

        $recovery = $this->client->call(Client::METHOD_POST, '/account/recovery', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'url' => 'http://localhost/recovery',
        ]);

        $this->assertEquals(201, $recovery['headers']['status-code']);
        $this->assertIsArray($recovery['body']);

        $webhook = $this->getLastRequest();

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Event'], 'account.recovery.create');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], 'not-yet-implemented');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-User-Id']), true);
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertNotEmpty($webhook['data']['userId']);
        $this->assertNotEmpty($webhook['data']['secret']);
        $this->assertIsNumeric($webhook['data']['expire']);

        $data['secret'] = $webhook['data']['secret'];
        
        return $data;
    }

    /**
     * @depends testCreateAccountRecovery
     */
    public function testUpdateAccountRecovery($data): array
    {
        $id = $data['id'] ?? '';
        $email = $data['email'] ?? '';
        $session = $data['session'] ?? '';
        $secret = $data['secret'] ?? '';
        $password = 'newPassowrd2';

        $recovery = $this->client->call(Client::METHOD_PUT, '/account/recovery', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => $id,
            'secret' => $secret,
            'password' => $password,
            'passwordAgain' => $password,
        ]);

        $this->assertEquals(200, $recovery['headers']['status-code']);
        $this->assertIsArray($recovery['body']);

        $webhook = $this->getLastRequest();

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Event'], 'account.recovery.update');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], 'not-yet-implemented');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-User-Id']), true);
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertNotEmpty($webhook['data']['userId']);
        $this->assertNotEmpty($webhook['data']['secret']);
        $this->assertIsNumeric($webhook['data']['expire']);

        $data['secret'] = $webhook['data']['secret'];

        return $data;
    }

    /**
     * @depends testUpdateAccountPrefs
     */
    public function testCreateAccountVerification($data): array
    {
        $id = $data['id'] ?? '';
        $email = $data['email'] ?? '';
        $session = $data['session'] ?? '';

        $verification = $this->client->call(Client::METHOD_POST, '/account/verification', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'aw'.$this->getProject()['$id'].'=' . $session,
        ]), [
            'url' => 'http://localhost/verification',
        ]);

        $this->assertEquals(201, $verification['headers']['status-code']);
        $this->assertIsArray($verification['body']);

        $webhook = $this->getLastRequest();

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Event'], 'account.verification.create');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], 'not-yet-implemented');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-User-Id'] ?? ''), ('server' === $this->getSide()));
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertNotEmpty($webhook['data']['userId']);
        $this->assertNotEmpty($webhook['data']['secret']);
        $this->assertIsNumeric($webhook['data']['expire']);

        $data['secret'] = $webhook['data']['secret'];

        return $data;
    }

    /**
     * @depends testCreateAccountVerification
     */
    public function testUpdateAccountVerification($data): array
    {
        $id = $data['id'] ?? '';
        $email = $data['email'] ?? '';
        $session = $data['session'] ?? '';
        $secret = $data['secret'] ?? '';

        $verification = $this->client->call(Client::METHOD_PUT, '/account/verification', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'aw'.$this->getProject()['$id'].'=' . $session,
        ]), [
            'userId' => $id,
            'secret' => $secret,
        ]);

        $this->assertEquals(200, $verification['headers']['status-code']);
        $this->assertIsArray($verification['body']);

        $webhook = $this->getLastRequest();

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Event'], 'account.verification.update');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], 'not-yet-implemented');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-User-Id'] ?? ''), ('server' === $this->getSide()));
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertNotEmpty($webhook['data']['userId']);
        $this->assertNotEmpty($webhook['data']['secret']);
        $this->assertIsNumeric($webhook['data']['expire']);

        $data['secret'] = $webhook['data']['secret'];

        return $data;
    }

    /**
     * @depends testCreateTeamMembership
     */
    public function testUpdateTeamMembership($data): array
    {
        $teamUid = $data['teamId'] ?? '';
        $secret = $data['secret'] ?? '';
        $inviteUid = $data['inviteId'] ?? '';
        $userUid = $data['userId'] ?? '';

        /**
         * Test for SUCCESS
         */
        $team = $this->client->call(Client::METHOD_PATCH, '/teams/'.$teamUid.'/memberships/'.$inviteUid.'/status', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'secret' => $secret,
            'userId' => $userUid,
        ]);

        $this->assertEquals(200, $team['headers']['status-code']);
        $this->assertNotEmpty($team['body']['$id']);

        $webhook = $this->getLastRequest();

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Event'], 'teams.memberships.update.status');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], 'not-yet-implemented');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-User-Id'] ?? ''), true);
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertNotEmpty($webhook['data']['userId']);
        $this->assertNotEmpty($webhook['data']['teamId']);
        $this->assertCount(2, $webhook['data']['roles']);
        $this->assertIsInt($webhook['data']['joined']);
        $this->assertEquals(true, $webhook['data']['confirm']);

        /**
         * Test for FAILURE
         */
        return [];
    }
}