<?php

namespace Tests\E2E\Services\Project;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Helpers\ID;

class AuthMethodsIntegrationTest extends Scope
{
    use ProjectCustom;
    use SideServer;

    public function testAuthMethodsIntegration(): void
    {
        $projectId = $this->getProject()['$id'];
        $apiKey = $this->getProject()['apiKey'];

        $serverHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apiKey,
        ];

        // Public headers carry no session / api key — this forces the shared
        // auth init to actually evaluate the auth-method gate (it is bypassed
        // for privileged / app users).
        $publicHeaders = [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ];

        $setAuthMethod = function (string $methodId, bool $enabled) use ($serverHeaders): void {
            $response = $this->client->call(
                Client::METHOD_PATCH,
                '/project/auth-methods/' . $methodId,
                $serverHeaders,
                ['enabled' => $enabled]
            );
            $this->assertSame(200, $response['headers']['status-code'], 'Failed to toggle ' . $methodId);
        };

        $methods = ['email-password', 'magic-url', 'email-otp', 'anonymous', 'invites', 'jwt', 'phone'];

        // Step 1 — Disable every auth method up front.
        foreach ($methods as $methodId) {
            $setAuthMethod($methodId, false);
        }

        $assertBlocked = function (array $response, string $context): void {
            $this->assertSame(501, $response['headers']['status-code'], $context . ' should be blocked with 501');
            $this->assertSame('user_auth_method_unsupported', $response['body']['type'] ?? '', $context . ' should return user_auth_method_unsupported');
        };

        $assertNotBlocked = function (array $response, string $context): void {
            $this->assertNotSame(501, $response['headers']['status-code'], $context . ' should not be blocked after enabling');
            $this->assertNotSame('user_auth_method_unsupported', $response['body']['type'] ?? '', $context . ' should not return user_auth_method_unsupported after enabling');
        };

        $email = 'auth_methods_' . \uniqid() . '@localhost.test';
        $password = 'password1234';

        // Step 2 — anonymous session creation.
        $anonymousAttempt = fn () => $this->client->call(Client::METHOD_POST, '/account/sessions/anonymous', $publicHeaders);

        $assertBlocked($anonymousAttempt(), 'Anonymous session (disabled)');
        $setAuthMethod('anonymous', true);
        $response = $anonymousAttempt();
        $assertNotBlocked($response, 'Anonymous session (enabled)');
        $this->assertSame(201, $response['headers']['status-code']);

        // Step 3 — email/password account creation.
        $createAccount = fn () => $this->client->call(Client::METHOD_POST, '/account', $publicHeaders, [
            'userId'   => ID::unique(),
            'email'    => $email,
            'password' => $password,
            'name'     => 'Auth Methods User',
        ]);

        $assertBlocked($createAccount(), 'Account creation (email-password disabled)');
        $setAuthMethod('email-password', true);
        $response = $createAccount();
        $assertNotBlocked($response, 'Account creation (email-password enabled)');
        $this->assertSame(201, $response['headers']['status-code']);
        $userId = $response['body']['$id'];

        // Step 4 — email/password session creation (still gated by email-password).
        // Disable momentarily to prove the session endpoint is gated too.
        $setAuthMethod('email-password', false);
        $emailSessionAttempt = fn () => $this->client->call(Client::METHOD_POST, '/account/sessions/email', $publicHeaders, [
            'email'    => $email,
            'password' => $password,
        ]);

        $assertBlocked($emailSessionAttempt(), 'Email/password session (disabled)');
        $setAuthMethod('email-password', true);
        $response = $emailSessionAttempt();
        $assertNotBlocked($response, 'Email/password session (enabled)');
        $this->assertSame(201, $response['headers']['status-code']);
        $sessionSecret = $response['cookies']['a_session_' . $projectId] ?? '';
        $this->assertNotEmpty($sessionSecret, 'Expected a session cookie after email/password login');

        // Step 5 — email OTP token.
        $emailOtpAttempt = fn () => $this->client->call(Client::METHOD_POST, '/account/tokens/email', $publicHeaders, [
            'userId' => $userId,
            'email'  => $email,
        ]);

        $assertBlocked($emailOtpAttempt(), 'Email OTP (disabled)');
        $setAuthMethod('email-otp', true);
        $response = $emailOtpAttempt();
        $assertNotBlocked($response, 'Email OTP (enabled)');
        $this->assertSame(201, $response['headers']['status-code']);

        // Step 6 — magic URL token.
        $magicUrlAttempt = fn () => $this->client->call(Client::METHOD_POST, '/account/tokens/magic-url', $publicHeaders, [
            'userId' => ID::unique(),
            'email'  => 'magic_' . \uniqid() . '@localhost.test',
        ]);

        $assertBlocked($magicUrlAttempt(), 'Magic URL (disabled)');
        $setAuthMethod('magic-url', true);
        $response = $magicUrlAttempt();
        $assertNotBlocked($response, 'Magic URL (enabled)');
        $this->assertSame(201, $response['headers']['status-code']);

        // Step 7 — phone token. After enabling the auth method the endpoint may
        // still fail for provider reasons — we only assert that the auth-method
        // gate stops fighting us.
        $phoneAttempt = fn () => $this->client->call(Client::METHOD_POST, '/account/tokens/phone', $publicHeaders, [
            'userId' => ID::unique(),
            'phone'  => '+14155550199',
        ]);

        $assertBlocked($phoneAttempt(), 'Phone token (disabled)');
        $setAuthMethod('phone', true);
        $assertNotBlocked($phoneAttempt(), 'Phone token (enabled)');

        // Step 8 — team invites. Needs an existing team; the session user
        // isn't a team owner, so we don't assert on 201 here — the gate itself
        // is what's under test and any non-501 proves it was lifted.
        $teamResponse = $this->client->call(Client::METHOD_POST, '/teams', $serverHeaders, [
            'teamId' => ID::unique(),
            'name'   => 'Auth Methods Team',
        ]);
        $this->assertSame(201, $teamResponse['headers']['status-code']);
        $teamId = $teamResponse['body']['$id'];

        $inviteHeaders = \array_merge($publicHeaders, [
            'cookie' => 'a_session_' . $projectId . '=' . $sessionSecret,
        ]);
        $inviteAttempt = fn () => $this->client->call(Client::METHOD_POST, '/teams/' . $teamId . '/memberships', $inviteHeaders, [
            'email' => 'invitee_' . \uniqid() . '@localhost.test',
            'roles' => ['developer'],
            'url'   => 'http://localhost/join',
        ]);

        $assertBlocked($inviteAttempt(), 'Team invite (disabled)');
        $setAuthMethod('invites', true);
        $assertNotBlocked($inviteAttempt(), 'Team invite (enabled)');

        // Step 9 — JWT creation. Requires an active session.
        $sessionHeaders = \array_merge($publicHeaders, [
            'cookie' => 'a_session_' . $projectId . '=' . $sessionSecret,
        ]);
        $jwtAttempt = fn () => $this->client->call(Client::METHOD_POST, '/account/jwts', $sessionHeaders);

        $assertBlocked($jwtAttempt(), 'JWT (disabled)');
        $setAuthMethod('jwt', true);
        $response = $jwtAttempt();
        $assertNotBlocked($response, 'JWT (enabled)');
        $this->assertSame(201, $response['headers']['status-code']);

        // Step 10 — End goal: GET /v1/account returns 200 using the session we
        // built via the (now enabled) email-password flow.
        $response = $this->client->call(Client::METHOD_GET, '/account', $sessionHeaders);
        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame($userId, $response['body']['$id']);
        $this->assertSame($email, $response['body']['email']);
    }
}
