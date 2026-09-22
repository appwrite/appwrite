<?php

declare(strict_types=1);

namespace Tests\E2E\Services\ProjectWebhooks;

use Appwrite\Tests\Retry;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Validator\Datetime as DatetimeValidator;

final class WebhooksCustomClientTest extends Scope
{
    use WebhooksBase;
    use ProjectCustom;
    use SideClient;

    /**
     * Creates a user account and returns account details with active session.
     *
     * @return array Array containing 'id', 'email', 'password', 'name', 'sessionId', 'session'
     */
    protected function setupAccountWithSession(): array
    {
        $email = uniqid() . 'user@localhost.test';
        $password = 'password';
        $name = 'User Name';

        // Create account
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

        // Create session
        $accountSession = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $sessionId = $accountSession['body']['$id'];
        $session = $accountSession['cookies']['a_session_' . $this->getProject()['$id']];

        return [
            'id' => $id,
            'email' => $email,
            'password' => $password,
            'name' => $name,
            'sessionId' => $sessionId,
            'session' => $session,
        ];
    }

    public function testCreateAccount(): void
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

        $this->assertEquals(201, $account['headers']['status-code']);
        $this->assertNotEmpty($account['body']);

        $webhook = $this->getLastRequest($this->webhookEventProbe("users.{$id}.create"));
        $signatureKey = $this->getProject()['signatureKey'];
        $payload = json_encode($webhook['data']);
        $url = $webhook['url'];
        $signatureExpected = base64_encode(hash_hmac('sha1', $url . $payload, $signatureKey, true));

        $this->assertEquals('POST', $webhook['method']);
        $this->assertEquals('application/json', $webhook['headers']['Content-Type']);
        $this->assertEquals('Appwrite-Server vdev. Please report abuse at security@appwrite.io', $webhook['headers']['User-Agent']);
        $this->assertStringContainsString('users.*', (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('users.*.create', (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.create", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], $signatureExpected);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-User-Id'], $id);
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertEquals($webhook['data']['name'], $name);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($webhook['data']['registration']));
        $this->assertEquals(true, $webhook['data']['status']);
        $this->assertEquals($webhook['data']['email'], $email);
        $this->assertEquals(false, $webhook['data']['emailVerification']);
        $this->assertEquals($webhook['data']['prefs'], []);
    }

    public function testDeleteAccount(): void
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

        $this->assertEquals(201, $accountSession['headers']['status-code']);

        $id = $account['body']['$id'];
        $session = $accountSession['cookies']['a_session_' . $this->getProject()['$id']];

        $account = $this->client->call(Client::METHOD_PATCH, '/account/status', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals(200, $account['headers']['status-code']);

        $webhook = $this->getLastRequest($this->webhookEventProbe("users.{$id}.update.status"));
        $signatureKey = $this->getProject()['signatureKey'];
        $payload = json_encode($webhook['data']);
        $url = $webhook['url'];
        $signatureExpected = base64_encode(hash_hmac('sha1', $url . $payload, $signatureKey, true));

        $this->assertEquals('POST', $webhook['method']);
        $this->assertEquals('application/json', $webhook['headers']['Content-Type']);
        $this->assertEquals('Appwrite-Server vdev. Please report abuse at security@appwrite.io', $webhook['headers']['User-Agent']);
        $this->assertStringContainsString('users.*', (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('users.*.update.status', (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.update.status", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], $signatureExpected);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertSame(empty($webhook['headers']['X-Appwrite-Webhook-User-Id'] ?? ''), ('server' === $this->getSide()));
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertEquals($webhook['data']['name'], $name);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($webhook['data']['registration']));
        $this->assertEquals(false, $webhook['data']['status']);
        $this->assertEquals($webhook['data']['email'], $email);
        $this->assertEquals(false, $webhook['data']['emailVerification']);
        $this->assertEquals($webhook['data']['prefs'], []);
    }

    public function testCreateAccountSession(): void
    {
        // Create a fresh account with unique email
        $email = 'webhook-session-' . uniqid() . '@localhost.test';
        $password = 'password';
        $name = 'User Name';

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

        // Verify account was created successfully
        $this->assertEquals(201, $account['headers']['status-code'], 'Account creation failed: ' . ($account['body']['message'] ?? 'unknown error'));
        $id = $account['body']['$id'];

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

        $this->assertEquals(201, $accountSession['headers']['status-code'], 'Session creation failed: ' . ($accountSession['body']['message'] ?? 'unknown error'));

        $sessionId = $accountSession['body']['$id'];
        $session = $accountSession['cookies']['a_session_' . $this->getProject()['$id']];

        $webhook = $this->getLastRequest($this->webhookEventProbe("users.{$id}.sessions.{$sessionId}.create"));
        $signatureKey = $this->getProject()['signatureKey'];
        $payload = json_encode($webhook['data']);
        $url = $webhook['url'];
        $signatureExpected = base64_encode(hash_hmac('sha1', $url . $payload, $signatureKey, true));

        $this->assertEquals('POST', $webhook['method']);
        $this->assertEquals('application/json', $webhook['headers']['Content-Type']);
        $this->assertEquals('Appwrite-Server vdev. Please report abuse at security@appwrite.io', $webhook['headers']['User-Agent']);
        $this->assertStringContainsString('users.*', (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('users.*.sessions.*', (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('users.*.sessions.*.create', (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.*.sessions.{$sessionId}", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.*.sessions.{$sessionId}.create", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.sessions.*", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.sessions.*.create", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.sessions.{$sessionId}", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.sessions.{$sessionId}.create", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], $signatureExpected);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-User-Id'], $id);
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertNotEmpty($webhook['data']['userId']);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($webhook['data']['expire']));
        $this->assertNotEmpty($webhook['data']['osCode']);
        $this->assertIsString($webhook['data']['osCode']);
        $this->assertNotEmpty($webhook['data']['osName']);
        $this->assertIsString($webhook['data']['osName']);
        $this->assertNotEmpty($webhook['data']['osVersion']);
        $this->assertIsString($webhook['data']['osVersion']);
        $this->assertEquals('browser', $webhook['data']['clientType']);
        $this->assertEquals('CH', $webhook['data']['clientCode']);
        $this->assertEquals('Chrome', $webhook['data']['clientName']);
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
        $this->assertEquals(true, $webhook['data']['current']);
    }

    public function testDeleteAccountSession(): void
    {
        // Set up account with session
        $data = $this->setupAccountWithSession();
        $id = $data['id'];
        $email = $data['email'];
        $password = $data['password'];

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
        $session = $accountSession['cookies']['a_session_' . $this->getProject()['$id']];

        $this->assertEquals(201, $accountSession['headers']['status-code']);

        $accountSession = $this->client->call(Client::METHOD_DELETE, '/account/sessions/' . $sessionId, array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals(204, $accountSession['headers']['status-code']);

        $webhook = $this->getLastRequest($this->webhookEventProbe("users.{$id}.sessions.{$sessionId}.delete"));
        $signatureKey = $this->getProject()['signatureKey'];
        $payload = json_encode($webhook['data']);
        $url = $webhook['url'];
        $signatureExpected = base64_encode(hash_hmac('sha1', $url . $payload, $signatureKey, true));

        $this->assertEquals('POST', $webhook['method']);
        $this->assertEquals('application/json', $webhook['headers']['Content-Type']);
        $this->assertEquals('Appwrite-Server vdev. Please report abuse at security@appwrite.io', $webhook['headers']['User-Agent']);
        $this->assertStringContainsString('users.*', (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('users.*.sessions.*', (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('users.*.sessions.*.delete', (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.*.sessions.{$sessionId}", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.*.sessions.{$sessionId}.delete", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.sessions.*", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.sessions.*.delete", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.sessions.{$sessionId}", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.sessions.{$sessionId}.delete", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], $signatureExpected);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertSame(empty($webhook['headers']['X-Appwrite-Webhook-User-Id'] ?? ''), ('server' === $this->getSide()));
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertNotEmpty($webhook['data']['userId']);
        $this->assertIsString($webhook['data']['expire']);
        $this->assertNotEmpty($webhook['data']['osCode']);
        $this->assertIsString($webhook['data']['osCode']);
        $this->assertNotEmpty($webhook['data']['osName']);
        $this->assertIsString($webhook['data']['osName']);
        $this->assertNotEmpty($webhook['data']['osVersion']);
        $this->assertIsString($webhook['data']['osVersion']);
        $this->assertEquals('browser', $webhook['data']['clientType']);
        $this->assertEquals('CH', $webhook['data']['clientCode']);
        $this->assertEquals('Chrome', $webhook['data']['clientName']);
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
        $this->assertEquals(true, $webhook['data']['current']);
    }

    public function testDeleteAccountSessions(): void
    {
        // Set up account with session
        $data = $this->setupAccountWithSession();
        $id = $data['id'];
        $email = $data['email'];
        $password = $data['password'];

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
        $session = $accountSession['cookies']['a_session_' . $this->getProject()['$id']];

        $this->assertEquals(201, $accountSession['headers']['status-code']);

        $accountSession = $this->client->call(Client::METHOD_DELETE, '/account/sessions', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals(204, $accountSession['headers']['status-code']);

        $webhook = $this->getLastRequest($this->webhookEventProbe("users.{$id}.sessions.{$sessionId}.delete"));
        $signatureKey = $this->getProject()['signatureKey'];
        $payload = json_encode($webhook['data']);
        $url = $webhook['url'];
        $signatureExpected = base64_encode(hash_hmac('sha1', $url . $payload, $signatureKey, true));

        $this->assertEquals('POST', $webhook['method']);
        $this->assertEquals('application/json', $webhook['headers']['Content-Type']);
        $this->assertEquals('Appwrite-Server vdev. Please report abuse at security@appwrite.io', $webhook['headers']['User-Agent']);
        $this->assertStringContainsString('users.*', (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('users.*.sessions.*', (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('users.*.sessions.*.delete', (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.*.sessions.{$sessionId}", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.*.sessions.{$sessionId}.delete", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.sessions.*", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.sessions.*.delete", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.sessions.{$sessionId}", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.sessions.{$sessionId}.delete", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], $signatureExpected);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertSame(empty($webhook['headers']['X-Appwrite-Webhook-User-Id'] ?? ''), ('server' === $this->getSide()));
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertNotEmpty($webhook['data']['userId']);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($webhook['data']['expire']));
        $this->assertNotEmpty($webhook['data']['osCode']);
        $this->assertIsString($webhook['data']['osCode']);
        $this->assertNotEmpty($webhook['data']['osName']);
        $this->assertIsString($webhook['data']['osName']);
        $this->assertNotEmpty($webhook['data']['osVersion']);
        $this->assertIsString($webhook['data']['osVersion']);
        $this->assertEquals('browser', $webhook['data']['clientType']);
        $this->assertEquals('CH', $webhook['data']['clientCode']);
        $this->assertEquals('Chrome', $webhook['data']['clientName']);
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
        $this->assertEquals(true, $webhook['data']['current']);
    }

    #[Retry(count: 1)]
    public function testUpdateAccountName(): void
    {
        // Set up account with session
        $data = $this->setupAccountWithSession();
        $id = $data['id'];
        $email = $data['email'];
        $session = $data['session'];
        $newName = 'New Name';

        $account = $this->client->call(Client::METHOD_PATCH, '/account/name', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'name' => $newName
        ]);

        $this->assertEquals(200, $account['headers']['status-code']);
        $this->assertIsArray($account['body']);

        $webhook = $this->getLastRequest($this->webhookEventProbe("users.{$id}.update.name"));
        $signatureKey = $this->getProject()['signatureKey'];
        $payload = json_encode($webhook['data']);
        $url = $webhook['url'];
        $signatureExpected = base64_encode(hash_hmac('sha1', $url . $payload, $signatureKey, true));


        $this->assertEquals('POST', $webhook['method']);
        $this->assertEquals('application/json', $webhook['headers']['Content-Type']);
        $this->assertEquals('Appwrite-Server vdev. Please report abuse at security@appwrite.io', $webhook['headers']['User-Agent']);
        $this->assertStringContainsString('users.*', (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('users.*.update', (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('users.*.update.name', (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.update", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.update.name", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], $signatureExpected);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertSame(empty($webhook['headers']['X-Appwrite-Webhook-User-Id'] ?? ''), ('server' === $this->getSide()));
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertEquals($webhook['data']['name'], $newName);
        $this->assertIsString($webhook['data']['registration']);
        $this->assertEquals(true, $webhook['data']['status']);
        $this->assertEquals($webhook['data']['email'], $email);
        $this->assertEquals(false, $webhook['data']['emailVerification']);
        $this->assertEquals($webhook['data']['prefs'], []);
    }

    public function testUpdateAccountPassword(): void
    {
        // Set up account with session
        $data = $this->setupAccountWithSession();
        $id = $data['id'];
        $email = $data['email'];
        $password = $data['password'];
        $session = $data['session'];

        // Update name first to make test self-sufficient
        // (In parallel execution, testUpdateAccountName may not have run)
        $this->client->call(Client::METHOD_PATCH, '/account/name', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'name' => 'New Name'
        ]);

        $account = $this->client->call(Client::METHOD_PATCH, '/account/password', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'password' => 'new-password',
            'oldPassword' => $password,
        ]);

        $this->assertEquals(200, $account['headers']['status-code']);
        $this->assertIsArray($account['body']);

        $webhook = $this->getLastRequest($this->webhookEventProbe("users.{$id}.update.password"));
        $signatureKey = $this->getProject()['signatureKey'];
        $payload = json_encode($webhook['data']);
        $url = $webhook['url'];
        $signatureExpected = base64_encode(hash_hmac('sha1', $url . $payload, $signatureKey, true));

        $this->assertEquals('POST', $webhook['method']);
        $this->assertEquals('application/json', $webhook['headers']['Content-Type']);
        $this->assertEquals('Appwrite-Server vdev. Please report abuse at security@appwrite.io', $webhook['headers']['User-Agent']);
        $this->assertStringContainsString('users.*', (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('users.*.update', (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('users.*.update.password', (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.update", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.update.password", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], $signatureExpected);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertSame(empty($webhook['headers']['X-Appwrite-Webhook-User-Id'] ?? ''), ('server' === $this->getSide()));
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertEquals('New Name', $webhook['data']['name']);
        $this->assertIsString($webhook['data']['registration']);
        $this->assertEquals(true, $webhook['data']['status']);
        $this->assertEquals($webhook['data']['email'], $email);
        $this->assertEquals(false, $webhook['data']['emailVerification']);
        $this->assertEquals($webhook['data']['prefs'], []);
    }

    public function testUpdateAccountEmail(): void
    {
        // Set up account with session
        $data = $this->setupAccountWithSession();
        $id = $data['id'];
        $email = $data['email'];
        $password = $data['password'];
        $newEmail = uniqid() . 'new@localhost.test';
        $session = $data['session'];

        // Update name first to make test self-sufficient
        // (In parallel execution, testUpdateAccountName may not have run)
        $this->client->call(Client::METHOD_PATCH, '/account/name', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'name' => 'New Name'
        ]);

        $account = $this->client->call(Client::METHOD_PATCH, '/account/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'email' => $newEmail,
            'password' => $password,
        ]);

        $this->assertEquals(200, $account['headers']['status-code']);
        $this->assertIsArray($account['body']);

        $webhook = $this->getLastRequest($this->webhookEventProbe("users.{$id}.update.email"));
        $signatureKey = $this->getProject()['signatureKey'];
        $payload = json_encode($webhook['data']);
        $url = $webhook['url'];
        $signatureExpected = base64_encode(hash_hmac('sha1', $url . $payload, $signatureKey, true));

        $this->assertEquals('POST', $webhook['method']);
        $this->assertEquals('application/json', $webhook['headers']['Content-Type']);
        $this->assertEquals('Appwrite-Server vdev. Please report abuse at security@appwrite.io', $webhook['headers']['User-Agent']);
        $this->assertStringContainsString('users.*', (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('users.*.update', (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('users.*.update.email', (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.update", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.update.email", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], $signatureExpected);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertSame(empty($webhook['headers']['X-Appwrite-Webhook-User-Id'] ?? ''), ('server' === $this->getSide()));
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertEquals('New Name', $webhook['data']['name']);
        $this->assertIsString($webhook['data']['registration']);
        $this->assertEquals(true, $webhook['data']['status']);
        $this->assertEquals($webhook['data']['email'], $newEmail);
        $this->assertEquals(false, $webhook['data']['emailVerification']);
        $this->assertEquals($webhook['data']['prefs'], []);
    }

    public function testUpdateAccountPrefs(): void
    {
        // Set up account with session
        $data = $this->setupAccountWithSession();
        $id = $data['id'];
        $email = $data['email'];
        $session = $data['session'];

        // Update name first to make test self-sufficient
        // (In parallel execution, testUpdateAccountName may not have run)
        $this->client->call(Client::METHOD_PATCH, '/account/name', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'name' => 'New Name'
        ]);

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

        $this->assertEquals(200, $account['headers']['status-code']);
        $this->assertIsArray($account['body']);

        $webhook = $this->getLastRequest($this->webhookEventProbe("users.{$id}.update.prefs"));
        $signatureKey = $this->getProject()['signatureKey'];
        $payload = json_encode($webhook['data']);
        $url = $webhook['url'];
        $signatureExpected = base64_encode(hash_hmac('sha1', $url . $payload, $signatureKey, true));

        $this->assertEquals('POST', $webhook['method']);
        $this->assertEquals('application/json', $webhook['headers']['Content-Type']);
        $this->assertEquals('Appwrite-Server vdev. Please report abuse at security@appwrite.io', $webhook['headers']['User-Agent']);
        $this->assertStringContainsString('users.*', (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('users.*.update', (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('users.*.update.prefs', (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.update", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.update.prefs", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], $signatureExpected);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertSame(empty($webhook['headers']['X-Appwrite-Webhook-User-Id'] ?? ''), ('server' === $this->getSide()));
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertEquals('New Name', $webhook['data']['name']);
        $this->assertIsString($webhook['data']['registration']);
        $this->assertEquals(true, $webhook['data']['status']);
        $this->assertEquals($webhook['data']['email'], $email);
        $this->assertEquals(false, $webhook['data']['emailVerification']);
        $this->assertEquals($webhook['data']['prefs'], [
            'prefKey1' => 'prefValue1',
            'prefKey2' => 'prefValue2',
        ]);
    }

    public function testCreateAccountVerification(): void
    {
        // Set up account with session
        $data = $this->setupAccountWithSession();
        $id = $data['id'];
        $email = $data['email'];
        $session = $data['session'];

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

        $webhook = $this->getLastRequest($this->webhookEventProbe("users.{$id}.verification.{$verificationId}.create"));
        $signatureKey = $this->getProject()['signatureKey'];
        $payload = json_encode($webhook['data']);
        $url = $webhook['url'];
        $signatureExpected = base64_encode(hash_hmac('sha1', $url . $payload, $signatureKey, true));

        $this->assertEquals('POST', $webhook['method']);
        $this->assertEquals('application/json', $webhook['headers']['Content-Type']);
        $this->assertEquals('Appwrite-Server vdev. Please report abuse at security@appwrite.io', $webhook['headers']['User-Agent']);
        $this->assertStringContainsString('users.*', (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('users.*.verification.*', (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('users.*.verification.*.create', (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.*.verification.{$verificationId}", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.*.verification.{$verificationId}.create", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.verification.*", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.verification.*.create", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.verification.{$verificationId}", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.verification.{$verificationId}.create", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], $signatureExpected);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertSame(empty($webhook['headers']['X-Appwrite-Webhook-User-Id'] ?? ''), ('server' === $this->getSide()));
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertNotEmpty($webhook['data']['userId']);
        $this->assertNotEmpty($webhook['data']['secret']);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($webhook['data']['expire']));
    }

    public function testUpdateAccountVerification(): void
    {
        // Set up account with session
        $data = $this->setupAccountWithSession();
        $id = $data['id'];
        $email = $data['email'];
        $session = $data['session'];

        // Create verification to get a secret
        $verification = $this->client->call(Client::METHOD_POST, '/account/verification', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'url' => 'http://localhost/verification',
        ]);

        // Get secret from webhook
        $webhook = $this->getLastRequest($this->webhookEventProbe("users.{$id}.verification.*.create"));
        $secret = $webhook['data']['secret'];

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

        $webhook = $this->getLastRequest($this->webhookEventProbe("users.{$id}.verification.{$verificationId}.update"));
        $signatureKey = $this->getProject()['signatureKey'];
        $payload = json_encode($webhook['data']);
        $url = $webhook['url'];
        $signatureExpected = base64_encode(hash_hmac('sha1', $url . $payload, $signatureKey, true));

        $this->assertEquals('POST', $webhook['method']);
        $this->assertEquals('application/json', $webhook['headers']['Content-Type']);
        $this->assertEquals('Appwrite-Server vdev. Please report abuse at security@appwrite.io', $webhook['headers']['User-Agent']);
        $this->assertStringContainsString('users.*', (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('users.*.verification.*', (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('users.*.verification.*.update', (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.*.verification.{$verificationId}", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.*.verification.{$verificationId}.update", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.verification.*", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.verification.*.update", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.verification.{$verificationId}", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.verification.{$verificationId}.update", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], $signatureExpected);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertSame(empty($webhook['headers']['X-Appwrite-Webhook-User-Id'] ?? ''), ('server' === $this->getSide()));
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertNotEmpty($webhook['data']['userId']);
        $this->assertNotEmpty($webhook['data']['secret']);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($webhook['data']['expire']));
    }

    public function testCreateAccountRecovery(): void
    {
        // Set up account with session
        $data = $this->setupAccountWithSession();
        $id = $data['id'];
        $email = $data['email'];

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

        $webhook = $this->getLastRequest($this->webhookEventProbe("users.{$id}.recovery.{$recoveryId}.create"));
        $signatureKey = $this->getProject()['signatureKey'];
        $payload = json_encode($webhook['data']);
        $url = $webhook['url'];
        $signatureExpected = base64_encode(hash_hmac('sha1', $url . $payload, $signatureKey, true));

        $this->assertEquals('POST', $webhook['method']);
        $this->assertEquals('application/json', $webhook['headers']['Content-Type']);
        $this->assertEquals('Appwrite-Server vdev. Please report abuse at security@appwrite.io', $webhook['headers']['User-Agent']);
        $this->assertStringContainsString('users.*', (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('users.*.recovery.*', (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('users.*.recovery.*.create', (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.*.recovery.{$recoveryId}", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.*.recovery.{$recoveryId}.create", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.recovery.*", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.recovery.*.create", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.recovery.{$recoveryId}", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.recovery.{$recoveryId}.create", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], $signatureExpected);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-User-Id'], $id);
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertNotEmpty($webhook['data']['userId']);
        $this->assertNotEmpty($webhook['data']['secret']);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($webhook['data']['expire']));
    }

    public function testUpdateAccountRecovery(): void
    {
        // Set up account with session
        $data = $this->setupAccountWithSession();
        $id = $data['id'];
        $email = $data['email'];
        $session = $data['session'];
        $password = 'newPassword2';

        // Create recovery to get a secret
        $recovery = $this->client->call(Client::METHOD_POST, '/account/recovery', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'url' => 'http://localhost/recovery',
        ]);

        // Get secret from webhook
        $webhook = $this->getLastRequest($this->webhookEventProbe("users.{$id}.recovery.*.create"));
        $secret = $webhook['data']['secret'];

        $recovery = $this->client->call(Client::METHOD_PUT, '/account/recovery', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => $id,
            'secret' => $secret,
            'password' => $password,
        ]);

        $recoveryId = $recovery['body']['$id'];

        $this->assertEquals(200, $recovery['headers']['status-code']);
        $this->assertIsArray($recovery['body']);

        $webhook = $this->getLastRequest($this->webhookEventProbe("users.{$id}.recovery.{$recoveryId}.update"));
        $signatureKey = $this->getProject()['signatureKey'];
        $payload = json_encode($webhook['data']);
        $url = $webhook['url'];
        $signatureExpected = base64_encode(hash_hmac('sha1', $url . $payload, $signatureKey, true));

        $this->assertEquals('POST', $webhook['method']);
        $this->assertEquals('application/json', $webhook['headers']['Content-Type']);
        $this->assertEquals('Appwrite-Server vdev. Please report abuse at security@appwrite.io', $webhook['headers']['User-Agent']);
        $this->assertStringContainsString('users.*', (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('users.*.recovery.*', (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('users.*.recovery.*.update', (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.*.recovery.{$recoveryId}", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.*.recovery.{$recoveryId}.update", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.recovery.*", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.recovery.*.update", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.recovery.{$recoveryId}", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("users.{$id}.recovery.{$recoveryId}.update", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], $signatureExpected);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-User-Id'], $id);
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertNotEmpty($webhook['data']['userId']);
        $this->assertNotEmpty($webhook['data']['secret']);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($webhook['data']['expire']));
    }

    public function testUpdateTeamMembership(): void
    {
        // Set up a team and create a membership
        $teamData = $this->setupTeam();
        $teamUid = $teamData['teamId'];
        $membershipData = $this->setupTeamMembership($teamUid);
        $secret = $membershipData['secret'];
        $membershipUid = $membershipData['membershipId'];
        $userUid = $membershipData['userId'];

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

        $webhook = $this->getLastRequest($this->webhookEventProbe("teams.{$teamUid}.memberships.{$membershipUid}.update.status"));
        $signatureKey = $this->getProject()['signatureKey'];
        $payload = json_encode($webhook['data']);
        $url = $webhook['url'];
        $signatureExpected = base64_encode(hash_hmac('sha1', $url . $payload, $signatureKey, true));

        $this->assertEquals('POST', $webhook['method']);
        $this->assertEquals('application/json', $webhook['headers']['Content-Type']);
        $this->assertEquals('Appwrite-Server vdev. Please report abuse at security@appwrite.io', $webhook['headers']['User-Agent']);
        $this->assertStringContainsString('teams.*', (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('teams.*.memberships.*', (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('teams.*.memberships.*.update', (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('teams.*.memberships.*.update.status', (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("teams.*.memberships.{$membershipUid}", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("teams.*.memberships.{$membershipUid}.update", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("teams.*.memberships.{$membershipUid}.update.status", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("teams.{$teamUid}", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("teams.{$teamUid}.memberships.*", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("teams.{$teamUid}.memberships.*.update", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("teams.{$teamUid}.memberships.*.update.status", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("teams.{$teamUid}.memberships.{$membershipUid}", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("teams.{$teamUid}.memberships.{$membershipUid}.update", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("teams.{$teamUid}.memberships.{$membershipUid}.update.status", (string) $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], $signatureExpected);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-User-Id'] ?? '', $userUid);
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertNotEmpty($webhook['data']['userId']);
        $this->assertNotEmpty($webhook['data']['teamId']);
        $this->assertCount(2, $webhook['data']['roles']);
        $this->assertTrue((new DatetimeValidator())->isValid($webhook['data']['joined']));
        $this->assertTrue($webhook['data']['confirm']);
    }
}
