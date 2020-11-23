<?php

namespace Tests\E2E\Services\Webhooks;

use CURLFile;
use Tests\E2E\Client;

trait WebhooksBase
{
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
        $session = $this->client->parseCookie((string)$accountSession['headers']['set-cookie'])['a_session_'.$this->getProject()['$id']];

        $webhook = $this->getLastRequest();

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Event'], 'account.sessions.create');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], 'not-yet-implemented');
        $this->assertNotEmpty($webhook['data']['$id']);
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
        $session = $this->client->parseCookie((string)$accountSession['headers']['set-cookie'])['a_session_'.$this->getProject()['$id']];

        $this->assertEquals($accountSession['headers']['status-code'], 201);

        $accountSession = $this->client->call(Client::METHOD_DELETE, '/account/sessions/'.$sessionId, array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $session,
        ]));

        $this->assertEquals($accountSession['headers']['status-code'], 204);

        $webhook = $this->getLastRequest();

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Event'], 'account.sessions.delete');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], 'not-yet-implemented');
        $this->assertNotEmpty($webhook['data']['$id']);
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

        $session = $this->client->parseCookie((string)$accountSession['headers']['set-cookie'])['a_session_'.$this->getProject()['$id']];

        $this->assertEquals($accountSession['headers']['status-code'], 201);

        $accountSession = $this->client->call(Client::METHOD_DELETE, '/account/sessions', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $session,
        ]));

        $this->assertEquals($accountSession['headers']['status-code'], 204);

        $webhook = $this->getLastRequest();

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Event'], 'account.sessions.delete');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], 'not-yet-implemented');
        $this->assertEquals($webhook['data']['sum'], 2);
        $this->assertNotEmpty($webhook['data']['sessions'][1]['$id']);
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
        $session = $this->client->parseCookie((string)$accountSession['headers']['set-cookie'])['a_session_'.$this->getProject()['$id']];

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
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $session,
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
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $session,
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
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $session,
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
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $session,
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

    public function testCreateFile():array
    {
        /**
         * Test for SUCCESS
         */
        $file = $this->client->call(Client::METHOD_POST, '/storage/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'logo.png'),
            'read' => ['*'],
            'write' => ['*'],
            'folderId' => 'xyz',
        ]);

        $this->assertEquals($file['headers']['status-code'], 201);
        $this->assertNotEmpty($file['body']['$id']);

        $webhook = $this->getLastRequest();

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Event'], 'storage.files.create');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], 'not-yet-implemented');
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertIsArray($webhook['data']['$permissions']);
        $this->assertEquals($webhook['data']['name'], 'logo.png');
        $this->assertIsInt($webhook['data']['dateCreated'], 'logo.png');
        $this->assertNotEmpty($webhook['data']['signature']);
        $this->assertEquals($webhook['data']['mimeType'], 'image/png');
        $this->assertEquals($webhook['data']['sizeOriginal'], 47218);

        /**
         * Test for FAILURE
         */
        return ['fileId' => $file['body']['$id']];
    }
    
    /**
     * @depends testCreateFile
     */
    public function testUpdateFile(array $data):array
    {
        /**
         * Test for SUCCESS
         */
        $file = $this->client->call(Client::METHOD_PUT, '/storage/files/' . $data['fileId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'read' => ['*'],
            'write' => ['*'],
        ]);

        $this->assertEquals($file['headers']['status-code'], 200);
        $this->assertNotEmpty($file['body']['$id']);

        $webhook = $this->getLastRequest();

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Event'], 'storage.files.update');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], 'not-yet-implemented');
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertIsArray($webhook['data']['$permissions']);
        $this->assertEquals($webhook['data']['name'], 'logo.png');
        $this->assertIsInt($webhook['data']['dateCreated'], 'logo.png');
        $this->assertNotEmpty($webhook['data']['signature']);
        $this->assertEquals($webhook['data']['mimeType'], 'image/png');
        $this->assertEquals($webhook['data']['sizeOriginal'], 47218);
        
        return $data;
    }
    
    /**
     * @depends testUpdateFile
     */
    public function testDeleteFile(array $data):array
    {
        /**
         * Test for SUCCESS
         */
        $file = $this->client->call(Client::METHOD_DELETE, '/storage/files/' . $data['fileId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(204, $file['headers']['status-code']);
        $this->assertEmpty($file['body']);

        $webhook = $this->getLastRequest();

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Event'], 'storage.files.delete');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], 'not-yet-implemented');
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertIsArray($webhook['data']['$permissions']);
        $this->assertEquals($webhook['data']['name'], 'logo.png');
        $this->assertIsInt($webhook['data']['dateCreated'], 'logo.png');
        $this->assertNotEmpty($webhook['data']['signature']);
        $this->assertEquals($webhook['data']['mimeType'], 'image/png');
        $this->assertEquals($webhook['data']['sizeOriginal'], 47218);
        
        return $data;
    }
}