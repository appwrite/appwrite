<?php

namespace Tests\E2E\Services\Webhooks;

use Appwrite\Tests\Retry;
use Tests\E2E\Client;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\SideClient;
use Utopia\Database\DateTime;
use Utopia\Database\Helpers\ID;

class WebhooksCustomClientTest extends Scope
{
    use WebhooksBase;
    use ProjectCustom;
    use SideClient;

    public function testCreateAccount(): array
    {
        $email = uniqid() . 'user@localhost.test';
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
            'userId' => ID::unique(),
            'email' => $email,
            'password' => $password,
            'name' => $name,
        ]);

        $id = $account['body']['$id'];

        $this->assertEquals($account['headers']['status-code'], 201);
        $this->assertNotEmpty($account['body']);

        $webhook = $this->getLastRequest();
        $signatureKey = $this->getProject()['signatureKey'];
        $payload = json_encode($webhook['data']);
        $url     = $webhook['url'];
        $signatureExpected = base64_encode(hash_hmac('sha1', $url . $payload, $signatureKey, true));

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertStringContainsString('users.*', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('users.*.create', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.create", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], $signatureExpected);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-User-Id']), true);
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertEquals($webhook['data']['name'], $name);
        $this->assertEquals(true, DateTime::isValid($webhook['data']['registration']));
        $this->assertEquals($webhook['data']['status'], true);
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

    public function testDeleteAccount(): array
    {
        $email = uniqid() . 'user1@localhost.test';
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
            'userId' => ID::unique(),
            'email' => $email,
            'password' => $password,
            'name' => $name,
        ]);

        $accountSession = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertEquals($accountSession['headers']['status-code'], 201);

        $id = $account['body']['$id'];
        $session = $this->client->parseCookie((string)$accountSession['headers']['set-cookie'])['a_session_' . $this->getProject()['$id']];

        $account = $this->client->call(Client::METHOD_PATCH, '/account/status', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals($account['headers']['status-code'], 200);

        $webhook = $this->getLastRequest();
        $signatureKey = $this->getProject()['signatureKey'];
        $payload = json_encode($webhook['data']);
        $url     = $webhook['url'];
        $signatureExpected = base64_encode(hash_hmac('sha1', $url . $payload, $signatureKey, true));

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertStringContainsString('users.*', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('users.*.update.status', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.update.status", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], $signatureExpected);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-User-Id'] ?? ''), ('server' === $this->getSide()));
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertEquals($webhook['data']['name'], $name);
        $this->assertEquals(true, DateTime::isValid($webhook['data']['registration']));
        $this->assertEquals($webhook['data']['status'], false);
        $this->assertEquals($webhook['data']['email'], $email);
        $this->assertEquals($webhook['data']['emailVerification'], false);
        $this->assertEquals($webhook['data']['prefs'], []);

        return [];
    }

    /**
     * @depends testCreateAccount
     */
    public function testCreateAccountSession($data): array
    {
        $id = $data['id'] ?? '';
        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';

        /**
         * Test for SUCCESS
         */
        $accountSession = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertEquals($accountSession['headers']['status-code'], 201);

        $sessionId = $accountSession['body']['$id'];
        $session = $this->client->parseCookie((string)$accountSession['headers']['set-cookie'])['a_session_' . $this->getProject()['$id']];

        $webhook = $this->getLastRequest();
        $signatureKey = $this->getProject()['signatureKey'];
        $payload = json_encode($webhook['data']);
        $url     = $webhook['url'];
        $signatureExpected = base64_encode(hash_hmac('sha1', $url . $payload, $signatureKey, true));

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertStringContainsString('users.*', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('users.*.sessions.*', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('users.*.sessions.*.create', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.*.sessions.{$sessionId}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.*.sessions.{$sessionId}.create", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.sessions.*", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.sessions.*.create", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.sessions.{$sessionId}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.sessions.{$sessionId}.create", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], $signatureExpected);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-User-Id']), true);
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertNotEmpty($webhook['data']['userId']);
        $this->assertEquals(true, DateTime::isValid($webhook['data']['expire']));
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
    public function testDeleteAccountSession($data): array
    {
        $id = $data['id'] ?? '';
        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';

        /**
         * Test for SUCCESS
         */
        $accountSession = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $sessionId = $accountSession['body']['$id'];
        $session = $this->client->parseCookie((string)$accountSession['headers']['set-cookie'])['a_session_' . $this->getProject()['$id']];

        $this->assertEquals($accountSession['headers']['status-code'], 201);

        $accountSession = $this->client->call(Client::METHOD_DELETE, '/account/sessions/' . $sessionId, array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals($accountSession['headers']['status-code'], 204);

        $webhook = $this->getLastRequest();
        $signatureKey = $this->getProject()['signatureKey'];
        $payload = json_encode($webhook['data']);
        $url     = $webhook['url'];
        $signatureExpected = base64_encode(hash_hmac('sha1', $url . $payload, $signatureKey, true));

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertStringContainsString('users.*', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('users.*.sessions.*', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('users.*.sessions.*.delete', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.*.sessions.{$sessionId}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.*.sessions.{$sessionId}.delete", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.sessions.*", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.sessions.*.delete", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.sessions.{$sessionId}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.sessions.{$sessionId}.delete", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], $signatureExpected);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-User-Id'] ?? ''), ('server' === $this->getSide()));
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertNotEmpty($webhook['data']['userId']);
        $this->assertIsString($webhook['data']['expire']);
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
    public function testDeleteAccountSessions($data): array
    {
        $id = $data['id'] ?? '';
        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';

        /**
         * Test for SUCCESS
         */
        $accountSession = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $sessionId = $accountSession['body']['$id'];
        $session = $this->client->parseCookie((string)$accountSession['headers']['set-cookie'])['a_session_' . $this->getProject()['$id']];

        $this->assertEquals($accountSession['headers']['status-code'], 201);

        $accountSession = $this->client->call(Client::METHOD_DELETE, '/account/sessions', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals($accountSession['headers']['status-code'], 204);

        $webhook = $this->getLastRequest();
        $signatureKey = $this->getProject()['signatureKey'];
        $payload = json_encode($webhook['data']);
        $url     = $webhook['url'];
        $signatureExpected = base64_encode(hash_hmac('sha1', $url . $payload, $signatureKey, true));

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertStringContainsString('users.*', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('users.*.sessions.*', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('users.*.sessions.*.delete', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.*.sessions.{$sessionId}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.*.sessions.{$sessionId}.delete", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.sessions.*", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.sessions.*.delete", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.sessions.{$sessionId}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.sessions.{$sessionId}.delete", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], $signatureExpected);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-User-Id'] ?? ''), ('server' === $this->getSide()));
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertNotEmpty($webhook['data']['userId']);
        $this->assertEquals(true, DateTime::isValid($webhook['data']['expire']));
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

        $accountSession = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertEquals($accountSession['headers']['status-code'], 201);

        $sessionId = $accountSession['body']['$id'];
        $session = $this->client->parseCookie((string)$accountSession['headers']['set-cookie'])['a_session_' . $this->getProject()['$id']];

        return array_merge($data, [
            'sessionId' => $sessionId,
            'session' => $session,
        ]);
    }

    /**
     * @depends testDeleteAccountSessions
     */
    #[Retry(count: 1)]
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
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'name' => $newName
        ]);

        $this->assertEquals($account['headers']['status-code'], 200);
        $this->assertIsArray($account['body']);

        $webhook = $this->getLastRequest();
        $signatureKey = $this->getProject()['signatureKey'];
        $payload = json_encode($webhook['data']);
        $url     = $webhook['url'];
        $signatureExpected = base64_encode(hash_hmac('sha1', $url . $payload, $signatureKey, true));


        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertStringContainsString('users.*', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('users.*.update', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('users.*.update.name', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.update", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.update.name", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], $signatureExpected);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-User-Id'] ?? ''), ('server' === $this->getSide()));
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertEquals($webhook['data']['name'], $newName);
        $this->assertIsString($webhook['data']['registration']);
        $this->assertEquals($webhook['data']['status'], true);
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
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'password' => 'new-password',
            'oldPassword' => $password,
        ]);

        $this->assertEquals($account['headers']['status-code'], 200);
        $this->assertIsArray($account['body']);

        $webhook = $this->getLastRequest();
        $signatureKey = $this->getProject()['signatureKey'];
        $payload = json_encode($webhook['data']);
        $url     = $webhook['url'];
        $signatureExpected = base64_encode(hash_hmac('sha1', $url . $payload, $signatureKey, true));

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertStringContainsString('users.*', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('users.*.update', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('users.*.update.password', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.update", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.update.password", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], $signatureExpected);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-User-Id'] ?? ''), ('server' === $this->getSide()));
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertEquals($webhook['data']['name'], 'New Name');
        $this->assertIsString($webhook['data']['registration']);
        $this->assertEquals($webhook['data']['status'], true);
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
        $newEmail = uniqid() . 'new@localhost.test';
        $session = $data['session'] ?? '';

        $account = $this->client->call(Client::METHOD_PATCH, '/account/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'email' => $newEmail,
            'password' => 'new-password',
        ]);

        $this->assertEquals($account['headers']['status-code'], 200);
        $this->assertIsArray($account['body']);

        $webhook = $this->getLastRequest();
        $signatureKey = $this->getProject()['signatureKey'];
        $payload = json_encode($webhook['data']);
        $url     = $webhook['url'];
        $signatureExpected = base64_encode(hash_hmac('sha1', $url . $payload, $signatureKey, true));

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertStringContainsString('users.*', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('users.*.update', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('users.*.update.email', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.update", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.update.email", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], $signatureExpected);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-User-Id'] ?? ''), ('server' === $this->getSide()));
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertEquals($webhook['data']['name'], 'New Name');
        $this->assertIsString($webhook['data']['registration']);
        $this->assertEquals($webhook['data']['status'], true);
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
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'prefs' => [
                'prefKey1' => 'prefValue1',
                'prefKey2' => 'prefValue2',
            ]
        ]);

        $this->assertEquals($account['headers']['status-code'], 200);
        $this->assertIsArray($account['body']);

        $webhook = $this->getLastRequest();
        $signatureKey = $this->getProject()['signatureKey'];
        $payload = json_encode($webhook['data']);
        $url     = $webhook['url'];
        $signatureExpected = base64_encode(hash_hmac('sha1', $url . $payload, $signatureKey, true));

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertStringContainsString('users.*', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('users.*.update', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('users.*.update.prefs', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.update", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.update.prefs", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], $signatureExpected);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-User-Id'] ?? ''), ('server' === $this->getSide()));
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertEquals($webhook['data']['name'], 'New Name');
        $this->assertIsString($webhook['data']['registration']);
        $this->assertEquals($webhook['data']['status'], true);
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

        $recovery = $this->client->call(Client::METHOD_POST, '/account/recovery', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'url' => 'http://localhost/recovery',
        ]);

        $recoveryId = $recovery['body']['$id'];

        $this->assertEquals(201, $recovery['headers']['status-code']);
        $this->assertIsArray($recovery['body']);

        $webhook = $this->getLastRequest();
        $signatureKey = $this->getProject()['signatureKey'];
        $payload = json_encode($webhook['data']);
        $url     = $webhook['url'];
        $signatureExpected = base64_encode(hash_hmac('sha1', $url . $payload, $signatureKey, true));

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertStringContainsString('users.*', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('users.*.recovery.*', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('users.*.recovery.*.create', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.*.recovery.{$recoveryId}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.*.recovery.{$recoveryId}.create", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.recovery.*", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.recovery.*.create", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.recovery.{$recoveryId}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.recovery.{$recoveryId}.create", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], $signatureExpected);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-User-Id'], $id);
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertNotEmpty($webhook['data']['userId']);
        $this->assertNotEmpty($webhook['data']['secret']);
        $this->assertEquals(true, DateTime::isValid($webhook['data']['expire']));

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

        $recoveryId = $recovery['body']['$id'];

        $this->assertEquals(200, $recovery['headers']['status-code']);
        $this->assertIsArray($recovery['body']);

        $webhook = $this->getLastRequest();
        $signatureKey = $this->getProject()['signatureKey'];
        $payload = json_encode($webhook['data']);
        $url     = $webhook['url'];
        $signatureExpected = base64_encode(hash_hmac('sha1', $url . $payload, $signatureKey, true));

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertStringContainsString('users.*', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('users.*.recovery.*', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('users.*.recovery.*.update', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.*.recovery.{$recoveryId}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.*.recovery.{$recoveryId}.update", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.recovery.*", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.recovery.*.update", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.recovery.{$recoveryId}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.recovery.{$recoveryId}.update", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], $signatureExpected);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-User-Id']), true);
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertNotEmpty($webhook['data']['userId']);
        $this->assertNotEmpty($webhook['data']['secret']);
        $this->assertEquals(true, DateTime::isValid($webhook['data']['expire']));

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
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'url' => 'http://localhost/verification',
        ]);

        $verificationId = $verification['body']['$id'];

        $this->assertEquals(201, $verification['headers']['status-code']);
        $this->assertIsArray($verification['body']);

        $webhook = $this->getLastRequest();
        $signatureKey = $this->getProject()['signatureKey'];
        $payload = json_encode($webhook['data']);
        $url     = $webhook['url'];
        $signatureExpected = base64_encode(hash_hmac('sha1', $url . $payload, $signatureKey, true));

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertStringContainsString('users.*', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('users.*.verification.*', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('users.*.verification.*.create', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.*.verification.{$verificationId}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.*.verification.{$verificationId}.create", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.verification.*", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.verification.*.create", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.verification.{$verificationId}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.verification.{$verificationId}.create", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], $signatureExpected);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-User-Id'] ?? ''), ('server' === $this->getSide()));
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertNotEmpty($webhook['data']['userId']);
        $this->assertNotEmpty($webhook['data']['secret']);
        $this->assertEquals(true, DateTime::isValid($webhook['data']['expire']));

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
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'userId' => $id,
            'secret' => $secret,
        ]);

        $verificationId = $verification['body']['$id'];

        $this->assertEquals(200, $verification['headers']['status-code']);
        $this->assertIsArray($verification['body']);

        $webhook = $this->getLastRequest();
        $signatureKey = $this->getProject()['signatureKey'];
        $payload = json_encode($webhook['data']);
        $url     = $webhook['url'];
        $signatureExpected = base64_encode(hash_hmac('sha1', $url . $payload, $signatureKey, true));

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertStringContainsString('users.*', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('users.*.verification.*', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('users.*.verification.*.update', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.*.verification.{$verificationId}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.*.verification.{$verificationId}.update", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.verification.*", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.verification.*.update", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.verification.{$verificationId}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.verification.{$verificationId}.update", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], $signatureExpected);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-User-Id'] ?? ''), ('server' === $this->getSide()));
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertNotEmpty($webhook['data']['userId']);
        $this->assertNotEmpty($webhook['data']['secret']);
        $this->assertEquals(true, DateTime::isValid($webhook['data']['expire']));

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
        $membershipUid = $data['membershipId'] ?? '';
        $userUid = $data['userId'] ?? '';

        /**
         * Test for SUCCESS
         */
        $team = $this->client->call(Client::METHOD_PATCH, '/teams/' . $teamUid . '/memberships/' . $membershipUid . '/status', array_merge([
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
        $signatureKey = $this->getProject()['signatureKey'];
        $payload = json_encode($webhook['data']);
        $url     = $webhook['url'];
        $signatureExpected = base64_encode(hash_hmac('sha1', $url . $payload, $signatureKey, true));

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertStringContainsString('teams.*', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('teams.*.memberships.*', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('teams.*.memberships.*.update', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('teams.*.memberships.*.update.status', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("teams.*.memberships.{$membershipUid}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("teams.*.memberships.{$membershipUid}.update", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("teams.*.memberships.{$membershipUid}.update.status", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("teams.{$teamUid}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("teams.{$teamUid}.memberships.*", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("teams.{$teamUid}.memberships.*.update", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("teams.{$teamUid}.memberships.*.update.status", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("teams.{$teamUid}.memberships.{$membershipUid}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("teams.{$teamUid}.memberships.{$membershipUid}.update", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("teams.{$teamUid}.memberships.{$membershipUid}.update.status", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], $signatureExpected);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-User-Id'] ?? ''), true);
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertNotEmpty($webhook['data']['userId']);
        $this->assertNotEmpty($webhook['data']['teamId']);
        $this->assertCount(2, $webhook['data']['roles']);
        $this->assertEquals(true, DateTime::isValid($webhook['data']['joined']));
        $this->assertEquals(true, $webhook['data']['confirm']);

        /**
         * Test for FAILURE
         */
        return [];
    }
}
