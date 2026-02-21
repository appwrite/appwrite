<?php

namespace Tests\E2E\Services\Account;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectConsole;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;
use Utopia\Database\Helpers\ID;

class AccountConsoleClientTest extends Scope
{
    use AccountBase;
    use ProjectConsole;
    use SideClient;

    public function testDeleteAccount(): void
    {
        $email = uniqid() . 'user@localhost.test';
        $password = 'password';
        $name = 'User Name';

        $response = $this->client->call(Client::METHOD_POST, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => ID::unique(),
            'email' => $email,
            'password' => $password,
            'name' => $name,
        ]);

        $this->assertEquals($response['headers']['status-code'], 201);

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertEquals($response['headers']['status-code'], 201);

        $session = $response['cookies']['a_session_' . $this->getProject()['$id']];

        // create team
        $team = $this->client->call(Client::METHOD_POST, '/teams', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ], [
            'teamId' => 'unique()',
            'name' => 'myteam'
        ]);
        $this->assertEquals($team['headers']['status-code'], 201);

        $teamId = $team['body']['$id'];

        $response = $this->client->call(Client::METHOD_DELETE, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals($response['headers']['status-code'], 400);

        // DELETE TEAM
        $response = $this->client->call(Client::METHOD_DELETE, '/teams/' . $teamId, array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));
        $this->assertEquals($response['headers']['status-code'], 204);
        sleep(2);

        $response = $this->client->call(Client::METHOD_DELETE, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals($response['headers']['status-code'], 204);
    }

    public function testSessionAlert(): void
    {
        $email = uniqid() . 'session-alert@appwrite.io';
        $password = 'password123';
        $name = 'Session Alert Tester';

        // Create a new account
        $response = $this->client->call(Client::METHOD_POST, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-dev-key' => $this->getProject()['devKey'] ?? ''
        ]), [
            'userId' => ID::unique(),
            'email' => $email,
            'password' => $password,
            'name' => $name,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        // Create first session for the new account
        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'user-agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        // Create second session for the new account
        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'user-agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
        ]), [
            'email' => $email,
            'password' => $password,
        ]);


        // Check the alert email
        $lastEmail = $this->getLastEmail();

        $this->assertEquals($email, $lastEmail['to'][0]['address']);
        $this->assertStringContainsString('Security alert: new session', $lastEmail['subject']);
        $this->assertStringContainsString($response['body']['ip'], $lastEmail['text']); // IP Address
        $this->assertStringContainsString('Unknown', $lastEmail['text']); // Country
        $this->assertStringContainsString($response['body']['clientName'], $lastEmail['text']); // Client name
        $this->assertStringContainsStringIgnoringCase('Appwrite logo', $lastEmail['html']);

        // Verify no alert sent in OTP login
        $response = $this->client->call(Client::METHOD_POST, '/account/tokens/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => ID::unique(),
            'email' => 'otpuser2@appwrite.io'
        ]);

        $this->assertEquals($response['headers']['status-code'], 201);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertNotEmpty($response['body']['$createdAt']);
        $this->assertNotEmpty($response['body']['userId']);
        $this->assertNotEmpty($response['body']['expire']);
        $this->assertEmpty($response['body']['secret']);
        $this->assertEmpty($response['body']['phrase']);
        $this->assertStringContainsStringIgnoringCase('New login detected on '. $this->getProject()['name'], $lastEmail['text']);

        $userId = $response['body']['userId'];

        $lastEmail = $this->getLastEmail();

        $this->assertEquals('otpuser2@appwrite.io', $lastEmail['to'][0]['address']);
        $this->assertEquals('OTP for ' . $this->getProject()['name'] . ' Login', $lastEmail['subject']);

        // Find 6 concurrent digits in email text - OTP
        preg_match_all("/\b\d{6}\b/", $lastEmail['text'], $matches);
        $code = ($matches[0] ?? [])[0] ?? '';

        $this->assertNotEmpty($code);

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/token', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => $userId,
            'secret' => $code
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEquals($userId, $response['body']['userId']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertNotEmpty($response['body']['expire']);
        $this->assertEmpty($response['body']['secret']);

        $lastEmailId = $lastEmail['id'];
        $lastEmail = $this->getLastEmail();
        $this->assertEquals($lastEmailId, $lastEmail['id']);
    }
}
