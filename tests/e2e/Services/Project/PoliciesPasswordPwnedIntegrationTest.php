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

    // Reported as breached by the mock range endpoint, 12345 and 9 times respectively
    private const PWNED_PASSWORD = 'Password123!';
    private const RARELY_PWNED_PASSWORD = 'letmein1234';

    // The mock range endpoint the stack already uses through _APP_PWNED_PASSWORDS_ENDPOINT
    private const MOCK_ENDPOINT = 'http://localhost/v1/mock/tests/general/pwned-passwords';

    // Nothing listens here, so lookups fail
    private const DEAD_ENDPOINT = 'http://localhost:1/range';

    public function testCreateUserWithPolicyDisabled(): void
    {
        $this->updatePolicy(['enabled' => false]);

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
        $this->updatePolicy(['enabled' => true]);

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

        $this->updatePolicy(['enabled' => false]);
    }

    public function testUpdateUserPasswordWithPolicyEnabled(): void
    {
        $this->updatePolicy(['enabled' => true]);

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

        $this->updatePolicy(['enabled' => false]);
    }

    public function testCreateAccountWithPolicyEnabled(): void
    {
        $this->updatePolicy(['enabled' => true]);

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

        $this->updatePolicy(['enabled' => false]);
    }

    public function testUpdateAccountPasswordWithPolicyEnabled(): void
    {
        $this->updatePolicy(['enabled' => true]);

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

        $this->updatePolicy(['enabled' => false]);
    }

    public function testUpdateAccountRecoveryWithPolicyEnabled(): void
    {
        $this->updatePolicy(['enabled' => true]);

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

        $this->updatePolicy(['enabled' => false]);
    }

    public function testThreshold(): void
    {
        $this->updatePolicy(['enabled' => true, 'threshold' => 10]);

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/users', $this->serverHeaders(), [
            'userId' => ID::unique(),
            'email' => 'pwned_rare_' . \uniqid() . '@localhost.test',
            'password' => self::RARELY_PWNED_PASSWORD,
            'name' => 'Rarely Pwned User',
        ]);

        $this->assertSame(201, $response['headers']['status-code']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_POST, '/users', $this->serverHeaders(), [
            'userId' => ID::unique(),
            'email' => 'pwned_often_' . \uniqid() . '@localhost.test',
            'password' => self::PWNED_PASSWORD,
            'name' => 'Often Pwned User',
        ]);

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('password_pwned', $response['body']['type']);

        $this->updatePolicy(['enabled' => false, 'threshold' => 1]);
    }

    public function testCustomEndpoint(): void
    {
        $this->updatePolicy(['enabled' => true, 'endpoint' => self::MOCK_ENDPOINT]);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_POST, '/users', $this->serverHeaders(), [
            'userId' => ID::unique(),
            'email' => 'pwned_endpoint_' . \uniqid() . '@localhost.test',
            'password' => self::PWNED_PASSWORD,
            'name' => 'Endpoint User',
        ]);

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('password_pwned', $response['body']['type']);

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/users', $this->serverHeaders(), [
            'userId' => ID::unique(),
            'email' => 'pwned_endpoint_clean_' . \uniqid() . '@localhost.test',
            'password' => $this->cleanPassword(),
            'name' => 'Endpoint Clean User',
        ]);

        $this->assertSame(201, $response['headers']['status-code']);

        // An empty endpoint falls back to the server configuration
        $this->updatePolicy(['endpoint' => '']);

        $response = $this->client->call(Client::METHOD_POST, '/users', $this->serverHeaders(), [
            'userId' => ID::unique(),
            'email' => 'pwned_fallback_' . \uniqid() . '@localhost.test',
            'password' => self::PWNED_PASSWORD,
            'name' => 'Fallback User',
        ]);

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('password_pwned', $response['body']['type']);

        $this->updatePolicy(['enabled' => false]);
    }

    public function testFailClosed(): void
    {
        $this->updatePolicy(['enabled' => true, 'endpoint' => self::DEAD_ENDPOINT, 'failClosed' => true]);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_POST, '/users', $this->serverHeaders(), [
            'userId' => ID::unique(),
            'email' => 'pwned_outage_' . \uniqid() . '@localhost.test',
            'password' => $this->cleanPassword(),
            'name' => 'Outage User',
        ]);

        $this->assertSame(503, $response['headers']['status-code']);
        $this->assertSame('general_pwned_passwords_unavailable', $response['body']['type']);

        $response = $this->client->call(Client::METHOD_POST, '/account', $this->clientHeaders(), [
            'userId' => ID::unique(),
            'email' => 'pwned_outage_account_' . \uniqid() . '@localhost.test',
            'password' => $this->cleanPassword(),
            'name' => 'Outage Account',
        ]);

        $this->assertSame(503, $response['headers']['status-code']);
        $this->assertSame('general_pwned_passwords_unavailable', $response['body']['type']);

        // Forced reset needs the lookup, so sign-in fails closed as well
        $this->updatePolicy(['enabled' => false]);

        $email = 'pwned_outage_signin_' . \uniqid() . '@localhost.test';
        $password = $this->cleanPassword();

        $user = $this->client->call(Client::METHOD_POST, '/users', $this->serverHeaders(), [
            'userId' => ID::unique(),
            'email' => $email,
            'password' => $password,
            'name' => 'Outage Sign In User',
        ]);

        $this->assertSame(201, $user['headers']['status-code']);

        $this->updatePolicy(['enabled' => true, 'forceReset' => true]);

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', $this->clientHeaders(), [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertSame(503, $response['headers']['status-code']);
        $this->assertSame('general_pwned_passwords_unavailable', $response['body']['type']);

        /**
         * Test for SUCCESS
         */
        $this->updatePolicy(['failClosed' => false]);

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', $this->clientHeaders(), [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertSame(201, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/users', $this->serverHeaders(), [
            'userId' => ID::unique(),
            'email' => 'pwned_outage_open_' . \uniqid() . '@localhost.test',
            'password' => self::PWNED_PASSWORD,
            'name' => 'Outage Open User',
        ]);

        $this->assertSame(201, $response['headers']['status-code']);

        $this->updatePolicy(['enabled' => false, 'endpoint' => '', 'forceReset' => false, 'failClosed' => true]);
    }

    public function testForceResetOnSignIn(): void
    {
        $this->updatePolicy(['enabled' => false]);

        $email = 'pwned_signin_' . \uniqid() . '@localhost.test';

        $user = $this->client->call(Client::METHOD_POST, '/users', $this->serverHeaders(), [
            'userId' => ID::unique(),
            'email' => $email,
            'password' => self::PWNED_PASSWORD,
            'name' => 'Sign In User',
        ]);

        $this->assertSame(201, $user['headers']['status-code']);

        /**
         * Test for SUCCESS
         */
        $this->updatePolicy(['enabled' => true, 'forceReset' => false]);

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', $this->clientHeaders(), [
            'email' => $email,
            'password' => self::PWNED_PASSWORD,
        ]);

        $this->assertSame(201, $response['headers']['status-code']);

        /**
         * Test for FAILURE
         */
        $this->updatePolicy(['forceReset' => true]);

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', $this->clientHeaders(), [
            'email' => $email,
            'password' => self::PWNED_PASSWORD,
        ]);

        $this->assertSame(412, $response['headers']['status-code']);
        $this->assertSame('user_password_reset_required', $response['body']['type']);

        // A wrong password is still reported as such, not as a reset
        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', $this->clientHeaders(), [
            'email' => $email,
            'password' => 'wrong-' . self::PWNED_PASSWORD,
        ]);

        $this->assertSame(401, $response['headers']['status-code']);
        $this->assertSame('user_invalid_credentials', $response['body']['type']);

        /**
         * Test for SUCCESS
         */
        $password = $this->cleanPassword();

        $response = $this->client->call(Client::METHOD_PATCH, '/users/' . $user['body']['$id'] . '/password', $this->serverHeaders(), [
            'password' => $password,
        ]);

        $this->assertSame(200, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', $this->clientHeaders(), [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertSame(201, $response['headers']['status-code']);

        $this->updatePolicy(['enabled' => false, 'forceReset' => false]);
    }

    public function testUpdateAnonymousAccountEmail(): void
    {
        $this->updatePolicy(['enabled' => true]);

        $headers = $this->anonymousHeaders();
        $email = 'pwned_anonymous_' . \uniqid() . '@localhost.test';

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/account/email', $headers, [
            'email' => $email,
            'password' => self::PWNED_PASSWORD,
        ]);

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('password_pwned', $response['body']['type']);

        // Strength policy
        $this->client->call(Client::METHOD_PATCH, '/project/policies/password-strength', $this->serverHeaders(), ['min' => 12]);

        $response = $this->client->call(Client::METHOD_PATCH, '/account/email', $headers, [
            'email' => $email,
            'password' => 'Short1!x',
        ]);

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $response['body']['type']);

        $this->client->call(Client::METHOD_PATCH, '/project/policies/password-strength', $this->serverHeaders(), ['min' => 8]);

        // Dictionary policy
        $this->client->call(Client::METHOD_PATCH, '/project/policies/password-dictionary', $this->serverHeaders(), ['enabled' => true]);

        $response = $this->client->call(Client::METHOD_PATCH, '/account/email', $headers, [
            'email' => $email,
            'password' => 'football',
        ]);

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $response['body']['type']);

        $this->client->call(Client::METHOD_PATCH, '/project/policies/password-dictionary', $this->serverHeaders(), ['enabled' => false]);

        // Personal data policy
        $this->client->call(Client::METHOD_PATCH, '/project/policies/password-personal-data', $this->serverHeaders(), ['enabled' => true]);

        $response = $this->client->call(Client::METHOD_PATCH, '/account/email', $headers, [
            'email' => $email,
            'password' => \explode('@', $email)[0] . '-Pw!',
        ]);

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('password_personal_data', $response['body']['type']);

        $this->client->call(Client::METHOD_PATCH, '/project/policies/password-personal-data', $this->serverHeaders(), ['enabled' => false]);

        /**
         * Test for SUCCESS
         */
        $this->client->call(Client::METHOD_PATCH, '/project/policies/password-history', $this->serverHeaders(), ['total' => 1]);

        $password = $this->cleanPassword();

        $response = $this->client->call(Client::METHOD_PATCH, '/account/email', $headers, [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame($email, $response['body']['email']);

        // The first password entered the history, so it cannot be reused
        $response = $this->client->call(Client::METHOD_PATCH, '/account/password', $headers, [
            'password' => $password,
            'oldPassword' => $password,
        ]);

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('password_recently_used', $response['body']['type']);

        $this->client->call(Client::METHOD_PATCH, '/project/policies/password-history', $this->serverHeaders(), ['total' => 0]);
        $this->updatePolicy(['enabled' => false]);
    }

    public function testUpdateAnonymousAccountPhone(): void
    {
        $this->updatePolicy(['enabled' => true]);

        $headers = $this->anonymousHeaders();
        $phone = '+15' . \random_int(100000000, 999999999);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/account/phone', $headers, [
            'phone' => $phone,
            'password' => self::PWNED_PASSWORD,
        ]);

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('password_pwned', $response['body']['type']);

        $response = $this->client->call(Client::METHOD_PATCH, '/account/phone', $headers, [
            'phone' => $phone,
            'password' => 'short',
        ]);

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $response['body']['type']);

        // Dictionary policy
        $this->client->call(Client::METHOD_PATCH, '/project/policies/password-dictionary', $this->serverHeaders(), ['enabled' => true]);

        $response = $this->client->call(Client::METHOD_PATCH, '/account/phone', $headers, [
            'phone' => $phone,
            'password' => 'football',
        ]);

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $response['body']['type']);

        $this->client->call(Client::METHOD_PATCH, '/project/policies/password-dictionary', $this->serverHeaders(), ['enabled' => false]);

        // Personal data policy
        $this->client->call(Client::METHOD_PATCH, '/project/policies/password-personal-data', $this->serverHeaders(), ['enabled' => true]);

        $response = $this->client->call(Client::METHOD_PATCH, '/account/phone', $headers, [
            'phone' => $phone,
            'password' => 'pw-' . \substr($phone, 1) . '!',
        ]);

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('password_personal_data', $response['body']['type']);

        $this->client->call(Client::METHOD_PATCH, '/project/policies/password-personal-data', $this->serverHeaders(), ['enabled' => false]);

        /**
         * Test for SUCCESS
         */
        $this->client->call(Client::METHOD_PATCH, '/project/policies/password-history', $this->serverHeaders(), ['total' => 1]);

        $password = $this->cleanPassword();

        $response = $this->client->call(Client::METHOD_PATCH, '/account/phone', $headers, [
            'phone' => $phone,
            'password' => $password,
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame($phone, $response['body']['phone']);

        // The first password entered the history, so it cannot be reused
        $response = $this->client->call(Client::METHOD_PATCH, '/account/password', $headers, [
            'password' => $password,
            'oldPassword' => $password,
        ]);

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('password_recently_used', $response['body']['type']);

        $this->client->call(Client::METHOD_PATCH, '/project/policies/password-history', $this->serverHeaders(), ['total' => 0]);
        $this->updatePolicy(['enabled' => false]);
    }

    public function testUpdateAccountEmailWithExistingPassword(): void
    {
        $this->updatePolicy(['enabled' => false]);

        $email = 'pwned_existing_' . \uniqid() . '@localhost.test';

        $account = $this->client->call(Client::METHOD_POST, '/account', $this->clientHeaders(), [
            'userId' => ID::unique(),
            'email' => $email,
            'password' => self::PWNED_PASSWORD,
            'name' => 'Existing Account',
        ]);

        $this->assertSame(201, $account['headers']['status-code']);

        $session = $this->client->call(Client::METHOD_POST, '/account/sessions/email', $this->clientHeaders(), [
            'email' => $email,
            'password' => self::PWNED_PASSWORD,
        ]);

        $this->assertSame(201, $session['headers']['status-code']);

        $projectId = $this->getProject()['$id'];
        $headers = \array_merge($this->clientHeaders(), [
            'cookie' => 'a_session_' . $projectId . '=' . $session['cookies']['a_session_' . $projectId],
        ]);

        // Policies tightened after the password was set only apply to new passwords
        $this->updatePolicy(['enabled' => true]);
        $this->client->call(Client::METHOD_PATCH, '/project/policies/password-strength', $this->serverHeaders(), ['min' => 20]);

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/account/email', $headers, [
            'email' => 'pwned_existing_new_' . \uniqid() . '@localhost.test',
            'password' => self::PWNED_PASSWORD,
        ]);

        $this->assertSame(200, $response['headers']['status-code']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/account/email', $headers, [
            'email' => 'pwned_existing_wrong_' . \uniqid() . '@localhost.test',
            'password' => 'wrong-' . self::PWNED_PASSWORD,
        ]);

        $this->assertSame(401, $response['headers']['status-code']);
        $this->assertSame('user_invalid_credentials', $response['body']['type']);

        $this->client->call(Client::METHOD_PATCH, '/project/policies/password-strength', $this->serverHeaders(), ['min' => 8]);
        $this->updatePolicy(['enabled' => false]);
    }

    /**
     * Client headers carrying a fresh anonymous session.
     *
     * @return array<string, string>
     */
    private function anonymousHeaders(): array
    {
        $session = $this->client->call(Client::METHOD_POST, '/account/sessions/anonymous', $this->clientHeaders());

        $this->assertSame(201, $session['headers']['status-code']);

        $projectId = $this->getProject()['$id'];

        return \array_merge($this->clientHeaders(), [
            'cookie' => 'a_session_' . $projectId . '=' . $session['cookies']['a_session_' . $projectId],
        ]);
    }

    /**
     * @param array<string, bool|int|string> $params
     */
    private function updatePolicy(array $params): void
    {
        $response = $this->client->call(Client::METHOD_PATCH, '/project/policies/password-pwned', $this->serverHeaders(), $params);

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
