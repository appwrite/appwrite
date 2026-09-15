<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Project;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Helpers\ID;

/**
 * The stack points `_APP_PWNED_PASSWORDS_ENDPOINT` at the mock range endpoint in
 * `app/controllers/mock.php`, so no request ever reaches the real Have I Been Pwned service.
 */
final class PoliciesPasswordPwnedIntegrationTest extends Scope
{
    use ProjectCustom;
    use SideServer;

    // Reported as breached by the mock range endpoint
    private const PWNED_PASSWORD = 'Password123!';

    public function testCreateUserWithPolicyDisabled(): void
    {
        $this->updatePolicy(false);

        /**
         * Test for SUCCESS
         */
        $user = $this->client->call(Client::METHOD_POST, '/users', $this->serverHeaders(), [
            'userId' => ID::unique(),
            'email' => 'pwned_off_' . \uniqid() . '@localhost.test',
            'password' => self::PWNED_PASSWORD,
            'name' => 'Pwned Off User',
        ]);

        $this->assertSame(201, $user['headers']['status-code']);
        $this->assertNotEmpty($user['body']['$id']);

        $response = $this->client->call(Client::METHOD_PATCH, '/users/' . $user['body']['$id'] . '/password', $this->serverHeaders(), [
            'password' => self::PWNED_PASSWORD,
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
    }

    public function testCreateUserWithPolicyEnabled(): void
    {
        $this->updatePolicy(true);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_POST, '/users', $this->serverHeaders(), [
            'userId' => ID::unique(),
            'email' => 'pwned_on_' . \uniqid() . '@localhost.test',
            'password' => self::PWNED_PASSWORD,
            'name' => 'Pwned On User',
        ]);

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('password_pwned', $response['body']['type']);

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/users', $this->serverHeaders(), [
            'userId' => ID::unique(),
            'email' => 'pwned_clean_' . \uniqid() . '@localhost.test',
            'password' => $this->cleanPassword(),
            'name' => 'Clean User',
        ]);

        $this->assertSame(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);

        // Importing an already hashed password never exposes the plaintext, so the policy cannot apply
        $response = $this->client->call(Client::METHOD_POST, '/users/sha', $this->serverHeaders(), [
            'userId' => ID::unique(),
            'email' => 'pwned_hashed_' . \uniqid() . '@localhost.test',
            'password' => \sha1(self::PWNED_PASSWORD),
            'passwordVersion' => 'sha1',
            'name' => 'Hashed User',
        ]);

        $this->assertSame(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);

        $this->updatePolicy(false);
    }

    public function testUpdateUserPasswordWithPolicyEnabled(): void
    {
        $this->updatePolicy(true);

        $user = $this->client->call(Client::METHOD_POST, '/users', $this->serverHeaders(), [
            'userId' => ID::unique(),
            'email' => 'pwned_update_' . \uniqid() . '@localhost.test',
            'password' => $this->cleanPassword(),
            'name' => 'Update User',
        ]);

        $this->assertSame(201, $user['headers']['status-code']);
        $userId = $user['body']['$id'];

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/users/' . $userId . '/password', $this->serverHeaders(), [
            'password' => self::PWNED_PASSWORD,
        ]);

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('password_pwned', $response['body']['type']);

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/users/' . $userId . '/password', $this->serverHeaders(), [
            'password' => $this->cleanPassword(),
        ]);

        $this->assertSame(200, $response['headers']['status-code']);

        // Clearing the password is not a password to check
        $response = $this->client->call(Client::METHOD_PATCH, '/users/' . $userId . '/password', $this->serverHeaders(), [
            'password' => '',
        ]);

        $this->assertSame(200, $response['headers']['status-code']);

        $this->updatePolicy(false);
    }

    public function testCreateAccountWithPolicyEnabled(): void
    {
        $this->updatePolicy(true);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_POST, '/account', $this->clientHeaders(), [
            'userId' => ID::unique(),
            'email' => 'pwned_account_' . \uniqid() . '@localhost.test',
            'password' => self::PWNED_PASSWORD,
            'name' => 'Pwned Account',
        ]);

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('password_pwned', $response['body']['type']);

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/account', $this->clientHeaders(), [
            'userId' => ID::unique(),
            'email' => 'pwned_account_clean_' . \uniqid() . '@localhost.test',
            'password' => $this->cleanPassword(),
            'name' => 'Clean Account',
        ]);

        $this->assertSame(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);

        $this->updatePolicy(false);
    }

    public function testUpdateAccountPasswordWithPolicyEnabled(): void
    {
        $this->updatePolicy(true);

        $email = 'pwned_session_' . \uniqid() . '@localhost.test';
        $password = $this->cleanPassword();

        $account = $this->client->call(Client::METHOD_POST, '/account', $this->clientHeaders(), [
            'userId' => ID::unique(),
            'email' => $email,
            'password' => $password,
            'name' => 'Session Account',
        ]);

        $this->assertSame(201, $account['headers']['status-code']);

        $session = $this->client->call(Client::METHOD_POST, '/account/sessions/email', $this->clientHeaders(), [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertSame(201, $session['headers']['status-code']);

        $headers = \array_merge($this->clientHeaders(), [
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session['cookies']['a_session_' . $this->getProject()['$id']],
        ]);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/account/password', $headers, [
            'password' => self::PWNED_PASSWORD,
            'oldPassword' => $password,
        ]);

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('password_pwned', $response['body']['type']);

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/account/password', $headers, [
            'password' => $this->cleanPassword(),
            'oldPassword' => $password,
        ]);

        $this->assertSame(200, $response['headers']['status-code']);

        $this->updatePolicy(false);
    }

    public function testUpdateAccountRecoveryWithPolicyEnabled(): void
    {
        $this->updatePolicy(true);

        $email = 'pwned_recovery_' . \uniqid() . '@localhost.test';

        $account = $this->client->call(Client::METHOD_POST, '/account', $this->clientHeaders(), [
            'userId' => ID::unique(),
            'email' => $email,
            'password' => $this->cleanPassword(),
            'name' => 'Recovery Account',
        ]);

        $this->assertSame(201, $account['headers']['status-code']);
        $userId = $account['body']['$id'];

        $recovery = $this->client->call(Client::METHOD_POST, '/account/recovery', $this->clientHeaders(), [
            'email' => $email,
            'url' => 'http://localhost/recovery',
        ]);

        $this->assertSame(201, $recovery['headers']['status-code']);

        $lastEmail = $this->getLastEmailByAddress($email, function ($email) {
            $this->assertStringContainsString('Password Reset', (string) $email['subject']);
        });
        $secret = $this->extractQueryParamsFromEmailLink($lastEmail['html'])['secret'];

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_PUT, '/account/recovery', $this->clientHeaders(), [
            'userId' => $userId,
            'secret' => $secret,
            'password' => self::PWNED_PASSWORD,
        ]);

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('password_pwned', $response['body']['type']);

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_PUT, '/account/recovery', $this->clientHeaders(), [
            'userId' => $userId,
            'secret' => $secret,
            'password' => $this->cleanPassword(),
        ]);

        $this->assertSame(200, $response['headers']['status-code']);

        $this->updatePolicy(false);
    }

    private function updatePolicy(bool $enabled): void
    {
        $response = $this->client->call(Client::METHOD_PATCH, '/project/policies/password-pwned', $this->serverHeaders(), [
            'enabled' => $enabled,
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
    }

    /**
     * @return array<string, string>
     */
    private function serverHeaders(): array
    {
        return \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders());
    }

    /**
     * @return array<string, string>
     */
    private function clientHeaders(): array
    {
        return [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];
    }

    private function cleanPassword(): string
    {
        return 'Clean-' . \uniqid() . '-Pw!';
    }
}
