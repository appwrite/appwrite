<?php

namespace Tests\E2E\Services\Account;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectConsole;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;
use Utopia\Validator\Datetime as DatetimeValidator;

class AccountSecurityEmailsTest extends Scope
{

    use AccountBase;
    use ProjectConsole;
    use SideClient;

    /**
     * @depends Tests\E2E\Services\Account\AccountCustomClientTest::testCreateSessionWithPhone
     */
    public function testSecurityEmailNotifications(array $data): array
    {
        $session = $data['session'];
        $project = $this->getProject();
        $password = 'user-password';
        $newEmail = uniqid() . 'updated@localhost.test';

        // Email change trigger
        $response = $this->client->call(Client::METHOD_PATCH, '/account/email', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $project['$id'],
            'cookie' => 'a_session_' . $project['$id'] . '=' . $session,
        ], [
            'email' => $newEmail,
            'password' => $password,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals($newEmail, $response['body']['email']);
        $this->assertTrue((new DatetimeValidator())->isValid($response['body']['registration']));

        // Password change trigger
        $newPassword = 'new-password-' . uniqid();
        $response = $this->client->call(Client::METHOD_PATCH, '/account/password', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $project['$id'],
            'cookie' => 'a_session_' . $project['$id'] . '=' . $session,
        ], [
            'password' => $newPassword,
            'oldPassword' => $password,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        // Session creation trigger (login alert)
        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $project['$id'],
        ], [
            'email' => $newEmail,
            'password' => $newPassword,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertIsArray($response['body']);
        $this->assertNotEmpty($response['body']['$id']);

        return $data;
    }
}
