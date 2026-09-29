<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Account;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;
use Utopia\Database\Helpers\ID;

final class PasskeysCustomClientTest extends Scope
{
    use ProjectCustom;
    use SideClient;

    private const string ORIGIN = 'http://localhost:3000';

    public function testPasskeysDisabledByDefault(): void
    {
        $project = $this->getProject(true);

        $response = $this->client->call(Client::METHOD_GET, '/project/policies/passkey', $this->getServerHeaders($project));
        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('', $response['body']['rpId']);
        $this->assertSame([], $response['body']['origins']);

        $response = $this->client->call(Client::METHOD_GET, '/project', $this->getServerHeaders($project));
        $methods = \array_column($response['body']['authMethods'], 'enabled', '$id');
        $this->assertFalse($methods['passkey']);

        $response = $this->client->call(Client::METHOD_POST, '/account/tokens/passkey', $this->getGuestHeaders($project));
        $this->assertSame(501, $response['headers']['status-code']);
        $this->assertSame('user_auth_method_unsupported', $response['body']['type']);

        // Enabled but unconfigured still fails closed
        $this->enablePasskeys($project, true);
        $response = $this->client->call(Client::METHOD_POST, '/account/tokens/passkey', $this->getGuestHeaders($project));
        $this->assertSame(501, $response['headers']['status-code']);
    }

    public function testUpdatePasskeyPolicy(): void
    {
        $project = $this->getProject(true);
        $headers = $this->getServerHeaders($project);

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/project/policies/passkey', $headers, [
            'rpId' => 'example.com',
            'origins' => ['https://example.com/', 'https://app.example.com:443', 'https://example.com:8443'],
        ]);
        $this->assertSame(200, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/project/policies/passkey', $headers);
        $this->assertSame('example.com', $response['body']['rpId']);
        $this->assertSame(['https://example.com', 'https://app.example.com', 'https://example.com:8443'], $response['body']['origins']);

        $list = $this->client->call(Client::METHOD_GET, '/project/policies', $headers);
        $this->assertContains('passkey', \array_column($list['body']['policies'], '$id'));

        // Sparse: origins alone keep the RP ID
        $response = $this->client->call(Client::METHOD_PATCH, '/project/policies/passkey', $headers, [
            'origins' => ['https://example.com'],
        ]);
        $this->assertSame(200, $response['headers']['status-code']);
        $response = $this->client->call(Client::METHOD_GET, '/project/policies/passkey', $headers);
        $this->assertSame('example.com', $response['body']['rpId']);
        $this->assertSame(['https://example.com'], $response['body']['origins']);

        /**
         * Test for FAILURE
         */
        foreach (['com', 'co.uk', 'github.io', '127.0.0.1', 'Example.com', 'exa_mple.com', 'https://example.com'] as $rpId) {
            $response = $this->client->call(Client::METHOD_PATCH, '/project/policies/passkey', $headers, [
                'rpId' => $rpId,
                'origins' => [],
            ]);
            $this->assertSame(400, $response['headers']['status-code'], $rpId);
        }

        foreach (['http://example.com', 'https://evil.com', 'https://notexample.com', 'https://example.com/login', 'https://user:pass@example.com', 'https://example.com?next=1', 'example.com'] as $origin) {
            $response = $this->client->call(Client::METHOD_PATCH, '/project/policies/passkey', $headers, [
                'rpId' => 'example.com',
                'origins' => [$origin],
            ]);
            $this->assertSame(400, $response['headers']['status-code'], $origin);
        }

        // HTTP is only for localhost as the RP ID
        $response = $this->client->call(Client::METHOD_PATCH, '/project/policies/passkey', $headers, [
            'rpId' => 'localhost',
            'origins' => ['http://localhost:3000', 'http://127.0.0.1:3000'],
        ]);
        $this->assertSame(400, $response['headers']['status-code']);

        // Clients cannot configure the relying party
        $response = $this->client->call(Client::METHOD_PATCH, '/project/policies/passkey', $this->getGuestHeaders($project), [
            'rpId' => 'example.com',
        ]);
        $this->assertSame(401, $response['headers']['status-code']);
    }

    public function testRegisterAndSignIn(): void
    {
        $project = $this->getProject(true);
        $this->configurePasskeys($project);
        [$user, $session] = $this->createUserWithSession($project);

        $authenticator = new Authenticator();

        /**
         * Test for SUCCESS
         */
        $challenge = $this->client->call(Client::METHOD_POST, '/account/passkeys', $this->getSessionHeaders($project, $session), [
            'name' => 'My laptop',
        ]);
        $this->assertSame(201, $challenge['headers']['status-code']);
        $this->assertNotEmpty($challenge['body']['$id']);
        $this->assertNotEmpty($challenge['body']['passkeyId']);
        $this->assertNotEmpty($challenge['body']['expire']);
        $options = $challenge['body']['publicKey'];
        $this->assertSame('localhost', $options['rp']['id']);
        $this->assertSame($user['email'], $options['user']['name']);
        $this->assertSame('required', $options['authenticatorSelection']['residentKey']);
        $this->assertSame('required', $options['authenticatorSelection']['userVerification']);
        $this->assertSame('none', $options['attestation']);

        // Pending passkeys are not listed
        $list = $this->client->call(Client::METHOD_GET, '/account/passkeys', $this->getSessionHeaders($project, $session));
        $this->assertSame(0, $list['body']['total']);

        $passkey = $this->client->call(Client::METHOD_PUT, '/account/passkeys/' . $challenge['body']['passkeyId'] . '/verification', $this->getSessionHeaders($project, $session), [
            'challengeId' => $challenge['body']['$id'],
            'credential' => $authenticator->register($options, self::ORIGIN),
        ]);
        $this->assertSame(200, $passkey['headers']['status-code']);
        $this->assertSame($challenge['body']['passkeyId'], $passkey['body']['$id']);
        $this->assertSame('My laptop', $passkey['body']['name']);
        $this->assertTrue($passkey['body']['backedUp']);
        $this->assertSame('', $passkey['body']['accessedAt']);
        $this->assertArrayNotHasKey('data', $passkey['body']);

        $list = $this->client->call(Client::METHOD_GET, '/account/passkeys', $this->getSessionHeaders($project, $session));
        $this->assertSame(200, $list['headers']['status-code']);
        $this->assertSame(1, $list['body']['total']);
        $this->assertSame($passkey['body']['$id'], $list['body']['passkeys'][0]['$id']);

        // Registering the same authenticator again is excluded up front
        $again = $this->client->call(Client::METHOD_POST, '/account/passkeys', $this->getSessionHeaders($project, $session));
        $this->assertSame(201, $again['headers']['status-code']);
        $this->assertSame(Authenticator::encode($authenticator->credentialId), $again['body']['publicKey']['excludeCredentials'][0]['id']);
        $this->assertSame($options['user']['id'], $again['body']['publicKey']['user']['id']);

        // Usernameless sign-in
        $token = $this->signIn($project, $authenticator);
        $this->assertSame(201, $token['headers']['status-code']);
        $this->assertSame($user['$id'], $token['body']['userId']);
        $this->assertNotEmpty($token['body']['secret']);

        $newSession = $this->client->call(Client::METHOD_POST, '/account/sessions/token', $this->getGuestHeaders($project), [
            'userId' => $token['body']['userId'],
            'secret' => $token['body']['secret'],
        ]);
        $this->assertSame(201, $newSession['headers']['status-code']);
        $this->assertSame('passkey', $newSession['body']['provider']);
        $this->assertSame(['passkey'], $newSession['body']['factors']);
        $cookie = $newSession['cookies']['a_session_' . $project['$id']];

        $account = $this->client->call(Client::METHOD_GET, '/account', $this->getSessionHeaders($project, $cookie));
        $this->assertSame(200, $account['headers']['status-code']);
        $this->assertSame($user['$id'], $account['body']['$id']);

        $list = $this->client->call(Client::METHOD_GET, '/account/passkeys', $this->getSessionHeaders($project, $cookie));
        $accessedAt = $list['body']['passkeys'][0]['accessedAt'];
        $this->assertStringEndsWith('+00:00', $accessedAt);
        $this->assertGreaterThanOrEqual(new \DateTime($list['body']['passkeys'][0]['$createdAt']), new \DateTime($accessedAt));

        /**
         * Test for FAILURE
         */
        // The token is single-use
        $replay = $this->client->call(Client::METHOD_POST, '/account/sessions/token', $this->getGuestHeaders($project), [
            'userId' => $token['body']['userId'],
            'secret' => $token['body']['secret'],
        ]);
        $this->assertSame(401, $replay['headers']['status-code']);

        // Other users cannot delete the passkey
        [, $otherSession] = $this->createUserWithSession($project);
        $response = $this->client->call(Client::METHOD_DELETE, '/account/passkeys/' . $passkey['body']['$id'], $this->getSessionHeaders($project, $otherSession));
        $this->assertSame(404, $response['headers']['status-code']);
        $this->assertSame('user_passkey_not_found', $response['body']['type']);

        // A deleted passkey can no longer sign in
        $response = $this->client->call(Client::METHOD_DELETE, '/account/passkeys/' . $passkey['body']['$id'], $this->getSessionHeaders($project, $session));
        $this->assertSame(204, $response['headers']['status-code']);

        $token = $this->signIn($project, $authenticator);
        $this->assertSame(401, $token['headers']['status-code']);
        $this->assertSame('user_passkey_invalid', $token['body']['type']);
    }

    public function testRegistrationFailures(): void
    {
        $project = $this->getProject(true);
        $this->configurePasskeys($project);
        [, $session] = $this->createUserWithSession($project);
        [, $otherSession] = $this->createUserWithSession($project);

        $verify = fn (array $challenge, array $credential, string $cookie, ?string $passkeyId = null) => $this->client->call(
            Client::METHOD_PUT,
            '/account/passkeys/' . ($passkeyId ?? $challenge['body']['passkeyId']) . '/verification',
            $this->getSessionHeaders($project, $cookie),
            ['challengeId' => $challenge['body']['$id'], 'credential' => $credential],
        );

        $start = fn (string $cookie) => $this->client->call(Client::METHOD_POST, '/account/passkeys', $this->getSessionHeaders($project, $cookie));

        // Guests cannot register
        $response = $this->client->call(Client::METHOD_POST, '/account/passkeys', $this->getGuestHeaders($project));
        $this->assertSame(401, $response['headers']['status-code']);

        // Wrong origin
        $challenge = $start($session);
        $response = $verify($challenge, (new Authenticator())->register($challenge['body']['publicKey'], 'http://localhost:4000'), $session);
        $this->assertSame(401, $response['headers']['status-code']);
        $this->assertSame('user_passkey_invalid', $response['body']['type']);

        // The challenge is consumed by the failed attempt
        $response = $verify($challenge, (new Authenticator())->register($challenge['body']['publicKey'], self::ORIGIN), $session);
        $this->assertSame(401, $response['headers']['status-code']);
        $this->assertSame('user_invalid_token', $response['body']['type']);

        // Wrong RP ID
        $challenge = $start($session);
        $response = $verify($challenge, (new Authenticator())->register($challenge['body']['publicKey'], self::ORIGIN, 'example.com'), $session);
        $this->assertSame(401, $response['headers']['status-code']);

        // User verification is required
        $challenge = $start($session);
        $response = $verify($challenge, (new Authenticator(userVerified: false))->register($challenge['body']['publicKey'], self::ORIGIN), $session);
        $this->assertSame(401, $response['headers']['status-code']);

        // Malformed credential
        $challenge = $start($session);
        $response = $verify($challenge, ['id' => 'abc', 'type' => 'public-key', 'response' => ['clientDataJSON' => 'e30']], $session);
        $this->assertSame(401, $response['headers']['status-code']);
        $this->assertSame('user_passkey_invalid', $response['body']['type']);

        // Challenge tampering: signed for a different challenge
        $challenge = $start($session);
        $options = $challenge['body']['publicKey'];
        $options['challenge'] = Authenticator::encode(\random_bytes(32));
        $response = $verify($challenge, (new Authenticator())->register($options, self::ORIGIN), $session);
        $this->assertSame(401, $response['headers']['status-code']);

        // Another user's challenge
        $challenge = $start($session);
        $response = $verify($challenge, (new Authenticator())->register($challenge['body']['publicKey'], self::ORIGIN), $otherSession);
        $this->assertSame(401, $response['headers']['status-code']);
        $this->assertSame('user_invalid_token', $response['body']['type']);

        // A challenge bound to one passkey cannot verify another
        $first = $start($session);
        $second = $start($session);
        $response = $verify($first, (new Authenticator())->register($first['body']['publicKey'], self::ORIGIN), $session, $second['body']['passkeyId']);
        $this->assertSame(401, $response['headers']['status-code']);

        // Relying party changes invalidate outstanding challenges
        $challenge = $start($session);
        $this->configurePasskeys($project, ['http://localhost:3000', 'http://localhost:5000']);
        $response = $verify($challenge, (new Authenticator())->register($challenge['body']['publicKey'], self::ORIGIN), $session);
        $this->assertSame(401, $response['headers']['status-code']);
        $this->assertSame('user_invalid_token', $response['body']['type']);
    }

    public function testConcurrentVerificationSucceedsOnce(): void
    {
        $project = $this->getProject(true);
        $this->configurePasskeys($project);
        [, $session] = $this->createUserWithSession($project);

        $challenge = $this->client->call(Client::METHOD_POST, '/account/passkeys', $this->getSessionHeaders($project, $session));
        $credential = (new Authenticator())->register($challenge['body']['publicKey'], self::ORIGIN);

        $statuses = $this->parallel(\array_fill(0, 5, [
            'method' => 'PUT',
            'path' => '/account/passkeys/' . $challenge['body']['passkeyId'] . '/verification',
            'headers' => $this->getSessionHeaders($project, $session),
            'body' => ['challengeId' => $challenge['body']['$id'], 'credential' => $credential],
        ]));

        $this->assertCount(1, \array_filter($statuses, fn (int $status) => $status === 200), \json_encode($statuses));
    }

    public function testSignInFailures(): void
    {
        $project = $this->getProject(true);
        $this->configurePasskeys($project);
        [$user, $session] = $this->createUserWithSession($project);
        $authenticator = $this->registerPasskey($project, $session);

        // Replaying a consumed challenge
        $challenge = $this->client->call(Client::METHOD_POST, '/account/tokens/passkey', $this->getGuestHeaders($project));
        $credential = $authenticator->authenticate($challenge['body']['publicKey'], self::ORIGIN);
        $first = $this->client->call(Client::METHOD_PUT, '/account/tokens/passkey', $this->getGuestHeaders($project), [
            'challengeId' => $challenge['body']['$id'],
            'credential' => $credential,
        ]);
        $this->assertSame(201, $first['headers']['status-code']);
        $second = $this->client->call(Client::METHOD_PUT, '/account/tokens/passkey', $this->getGuestHeaders($project), [
            'challengeId' => $challenge['body']['$id'],
            'credential' => $credential,
        ]);
        $this->assertSame(401, $second['headers']['status-code']);
        $this->assertSame('user_invalid_token', $second['body']['type']);

        // Wrong origin, wrong RP ID, cross-origin iframe, missing user verification
        $this->assertSame(401, $this->signIn($project, $authenticator, origin: 'http://localhost:4000')['headers']['status-code']);
        $this->assertSame(401, $this->signIn($project, $authenticator, rpId: 'example.com')['headers']['status-code']);
        $this->assertSame(401, $this->signIn($project, $authenticator, crossOrigin: true)['headers']['status-code']);
        $authenticator->userVerified = false;
        $this->assertSame(401, $this->signIn($project, $authenticator)['headers']['status-code']);
        $authenticator->userVerified = true;

        // Signature from a different key for the same credential ID
        $impostor = new Authenticator();
        $impostor->credentialId = $authenticator->credentialId;
        $impostor->userHandle = $authenticator->userHandle;
        $impostor->counter = 100;
        $this->assertSame(401, $this->signIn($project, $impostor)['headers']['status-code']);

        // Unknown credential
        $stranger = new Authenticator();
        $stranger->userHandle = $authenticator->userHandle;
        $response = $this->signIn($project, $stranger);
        $this->assertSame(401, $response['headers']['status-code']);
        $this->assertSame('user_passkey_invalid', $response['body']['type']);

        // Challenges from another project
        $otherProject = $this->getProject(true);
        $this->configurePasskeys($otherProject);
        $challenge = $this->client->call(Client::METHOD_POST, '/account/tokens/passkey', $this->getGuestHeaders($otherProject));
        $response = $this->client->call(Client::METHOD_PUT, '/account/tokens/passkey', $this->getGuestHeaders($project), [
            'challengeId' => $challenge['body']['$id'],
            'credential' => $authenticator->authenticate($challenge['body']['publicKey'], self::ORIGIN),
        ]);
        $this->assertSame(401, $response['headers']['status-code']);

        // Blocked users
        $this->client->call(Client::METHOD_PATCH, '/users/' . $user['$id'] . '/status', $this->getServerHeaders($project), [
            'status' => false,
        ]);
        $response = $this->signIn($project, $authenticator);
        $this->assertSame(403, $response['headers']['status-code']);
        $this->assertSame('user_blocked', $response['body']['type']);
        $this->client->call(Client::METHOD_PATCH, '/users/' . $user['$id'] . '/status', $this->getServerHeaders($project), [
            'status' => true,
        ]);

        // Still works after all of the above
        $this->assertSame(201, $this->signIn($project, $authenticator)['headers']['status-code']);
    }

    public function testDeviceBoundCounter(): void
    {
        $project = $this->getProject(true);
        $this->configurePasskeys($project);
        [, $session] = $this->createUserWithSession($project);
        $authenticator = $this->registerPasskey($project, $session, new Authenticator(backupEligible: false));

        $this->assertSame(201, $this->signIn($project, $authenticator)['headers']['status-code']);

        // A device-bound authenticator whose counter goes backwards may be cloned
        $authenticator->counter = 0;
        $response = $this->signIn($project, $authenticator);
        $this->assertSame(401, $response['headers']['status-code']);

        // Backup eligibility cannot change after registration
        $authenticator->counter = 10;
        $authenticator->backupEligible = true;
        $this->assertSame(401, $this->signIn($project, $authenticator)['headers']['status-code']);
    }

    public function testSyncedPasskeyCounter(): void
    {
        $project = $this->getProject(true);
        $this->configurePasskeys($project);
        [, $session] = $this->createUserWithSession($project);
        $authenticator = $this->registerPasskey($project, $session);

        // Synced passkeys report non-increasing counters across devices
        $authenticator->counter = 5;
        $this->assertSame(201, $this->signIn($project, $authenticator)['headers']['status-code']);
        $authenticator->counter = 1;
        $this->assertSame(201, $this->signIn($project, $authenticator)['headers']['status-code']);
    }

    public function testToggleOff(): void
    {
        $project = $this->getProject(true);
        $this->configurePasskeys($project);
        [, $session] = $this->createUserWithSession($project);
        $authenticator = $this->registerPasskey($project, $session);

        $challenge = $this->client->call(Client::METHOD_POST, '/account/tokens/passkey', $this->getGuestHeaders($project));
        $this->assertSame(201, $challenge['headers']['status-code']);

        $this->enablePasskeys($project, false);

        // New ceremonies are blocked
        $response = $this->client->call(Client::METHOD_POST, '/account/tokens/passkey', $this->getGuestHeaders($project));
        $this->assertSame(501, $response['headers']['status-code']);
        $response = $this->client->call(Client::METHOD_POST, '/account/passkeys', $this->getSessionHeaders($project, $session));
        $this->assertSame(501, $response['headers']['status-code']);

        // Challenges issued before may still complete until they expire
        $response = $this->client->call(Client::METHOD_PUT, '/account/tokens/passkey', $this->getGuestHeaders($project), [
            'challengeId' => $challenge['body']['$id'],
            'credential' => $authenticator->authenticate($challenge['body']['publicKey'], self::ORIGIN),
        ]);
        $this->assertSame(201, $response['headers']['status-code']);

        // Existing passkeys can still be listed and removed
        $list = $this->client->call(Client::METHOD_GET, '/account/passkeys', $this->getSessionHeaders($project, $session));
        $this->assertSame(1, $list['body']['total']);
    }

    public function testRelyingPartyLockedByPasskeys(): void
    {
        $project = $this->getProject(true);
        $this->configurePasskeys($project);
        [, $session] = $this->createUserWithSession($project);

        // Pending registrations do not lock the RP ID
        $this->client->call(Client::METHOD_POST, '/account/passkeys', $this->getSessionHeaders($project, $session));
        $response = $this->client->call(Client::METHOD_PATCH, '/project/policies/passkey', $this->getServerHeaders($project), [
            'rpId' => 'example.com',
            'origins' => ['https://example.com'],
        ]);
        $this->assertSame(200, $response['headers']['status-code']);

        $this->configurePasskeys($project);
        $this->registerPasskey($project, $session);

        $response = $this->client->call(Client::METHOD_PATCH, '/project/policies/passkey', $this->getServerHeaders($project), [
            'rpId' => 'example.com',
            'origins' => ['https://example.com'],
        ]);
        $this->assertSame(400, $response['headers']['status-code']);

        // Origins can still change
        $response = $this->client->call(Client::METHOD_PATCH, '/project/policies/passkey', $this->getServerHeaders($project), [
            'origins' => ['http://localhost:3000', 'http://localhost:5173'],
        ]);
        $this->assertSame(200, $response['headers']['status-code']);
    }

    public function testPasskeyLimit(): void
    {
        $project = $this->getProject(true);
        $this->configurePasskeys($project);
        [, $session] = $this->createUserWithSession($project);

        for ($i = 0; $i < 10; $i++) {
            $response = $this->client->call(Client::METHOD_POST, '/account/passkeys', $this->getSessionHeaders($project, $session));
            $this->assertSame(201, $response['headers']['status-code']);
        }

        $response = $this->client->call(Client::METHOD_POST, '/account/passkeys', $this->getSessionHeaders($project, $session));
        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('user_passkey_limit_exceeded', $response['body']['type']);
    }

    public function testAnonymousUsersCannotRegister(): void
    {
        $project = $this->getProject(true);
        $this->configurePasskeys($project);

        $anonymous = $this->client->call(Client::METHOD_POST, '/account/sessions/anonymous', $this->getGuestHeaders($project));
        $this->assertSame(201, $anonymous['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/account/passkeys', $this->getSessionHeaders($project, $anonymous['cookies']['a_session_' . $project['$id']]));
        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('user_passkey_unavailable', $response['body']['type']);
    }

    public function testMfaStillRequired(): void
    {
        $project = $this->getProject(true);
        $this->configurePasskeys($project);
        [$user, $session] = $this->createUserWithSession($project);
        $authenticator = $this->registerPasskey($project, $session);

        $this->client->call(Client::METHOD_PATCH, '/users/' . $user['$id'] . '/verification', $this->getServerHeaders($project), [
            'emailVerification' => true,
        ]);
        $response = $this->client->call(Client::METHOD_PATCH, '/users/' . $user['$id'] . '/mfa', $this->getServerHeaders($project), [
            'mfa' => true,
        ]);
        $this->assertSame(200, $response['headers']['status-code']);

        $token = $this->signIn($project, $authenticator);
        $newSession = $this->client->call(Client::METHOD_POST, '/account/sessions/token', $this->getGuestHeaders($project), [
            'userId' => $token['body']['userId'],
            'secret' => $token['body']['secret'],
        ]);
        $this->assertSame(201, $newSession['headers']['status-code']);
        $cookie = $newSession['cookies']['a_session_' . $project['$id']];

        // A passkey is one factor; it neither verifies nor bypasses the second
        $account = $this->client->call(Client::METHOD_GET, '/account', $this->getSessionHeaders($project, $cookie));
        $this->assertSame(401, $account['headers']['status-code']);
        $this->assertSame('user_more_factors_required', $account['body']['type']);

        $factors = $this->client->call(Client::METHOD_GET, '/account/mfa/factors', $this->getSessionHeaders($project, $cookie));
        $this->assertSame(200, $factors['headers']['status-code']);
        $this->assertTrue($factors['body']['email']);
    }

    public function testDeletedUserCannotSignIn(): void
    {
        $project = $this->getProject(true);
        $this->configurePasskeys($project);
        [$user, $session] = $this->createUserWithSession($project);
        $authenticator = $this->registerPasskey($project, $session);

        $response = $this->client->call(Client::METHOD_DELETE, '/users/' . $user['$id'], $this->getServerHeaders($project));
        $this->assertSame(204, $response['headers']['status-code']);

        // A new user reusing the public ID must not inherit the passkey
        $response = $this->client->call(Client::METHOD_POST, '/users', $this->getServerHeaders($project), [
            'userId' => $user['$id'],
            'email' => 'recreated.' . \uniqid() . '@localhost.test',
            'password' => 'password',
        ]);
        $this->assertSame(201, $response['headers']['status-code']);

        $response = $this->signIn($project, $authenticator);
        $this->assertSame(401, $response['headers']['status-code']);
        $this->assertSame('user_passkey_invalid', $response['body']['type']);
    }

    /**
     * @return array<string, mixed>
     */
    private function signIn(array $project, Authenticator $authenticator, string $origin = self::ORIGIN, ?string $rpId = null, bool $crossOrigin = false): array
    {
        $challenge = $this->client->call(Client::METHOD_POST, '/account/tokens/passkey', $this->getGuestHeaders($project));
        $this->assertSame(201, $challenge['headers']['status-code']);
        $this->assertSame('', $challenge['body']['passkeyId']);
        $this->assertSame([], $challenge['body']['publicKey']['allowCredentials']);

        return $this->client->call(Client::METHOD_PUT, '/account/tokens/passkey', $this->getGuestHeaders($project), [
            'challengeId' => $challenge['body']['$id'],
            'credential' => $authenticator->authenticate($challenge['body']['publicKey'], $origin, $rpId, $crossOrigin),
        ]);
    }

    private function registerPasskey(array $project, string $session, ?Authenticator $authenticator = null): Authenticator
    {
        $authenticator ??= new Authenticator();

        $challenge = $this->client->call(Client::METHOD_POST, '/account/passkeys', $this->getSessionHeaders($project, $session));
        $this->assertSame(201, $challenge['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_PUT, '/account/passkeys/' . $challenge['body']['passkeyId'] . '/verification', $this->getSessionHeaders($project, $session), [
            'challengeId' => $challenge['body']['$id'],
            'credential' => $authenticator->register($challenge['body']['publicKey'], self::ORIGIN),
        ]);
        $this->assertSame(200, $response['headers']['status-code'], \json_encode($response['body']));

        return $authenticator;
    }

    /**
     * @param array<string> $origins
     */
    private function configurePasskeys(array $project, array $origins = [self::ORIGIN]): void
    {
        $response = $this->client->call(Client::METHOD_PATCH, '/project/policies/passkey', $this->getServerHeaders($project), [
            'rpId' => 'localhost',
            'origins' => $origins,
        ]);
        $this->assertSame(200, $response['headers']['status-code'], \json_encode($response['body']));

        $this->enablePasskeys($project, true);
    }

    private function enablePasskeys(array $project, bool $enabled): void
    {
        $response = $this->client->call(Client::METHOD_PATCH, '/project/auth-methods/passkey', $this->getServerHeaders($project), [
            'enabled' => $enabled,
        ]);
        $this->assertSame(200, $response['headers']['status-code'], \json_encode($response['body']));
    }

    /**
     * @return array{array<string, mixed>, string}
     */
    private function createUserWithSession(array $project): array
    {
        $email = \uniqid('passkey.', true) . \bin2hex(\random_bytes(4)) . '@localhost.test';

        $user = $this->client->call(Client::METHOD_POST, '/account', $this->getGuestHeaders($project), [
            'userId' => ID::unique(),
            'email' => $email,
            'password' => 'password',
            'name' => 'Passkey User',
        ]);
        $this->assertSame(201, $user['headers']['status-code']);

        $session = $this->client->call(Client::METHOD_POST, '/account/sessions/email', $this->getGuestHeaders($project), [
            'email' => $email,
            'password' => 'password',
        ]);
        $this->assertSame(201, $session['headers']['status-code']);

        return [$user['body'], $session['cookies']['a_session_' . $project['$id']]];
    }

    /**
     * Fires requests at once to race them against each other.
     *
     * @param array<array{method: string, path: string, headers: array<string, string>, body: array<string, mixed>}> $requests
     * @return array<int>
     */
    private function parallel(array $requests): array
    {
        $multi = \curl_multi_init();
        $handles = [];
        foreach ($requests as $request) {
            $handle = \curl_init($this->client->getEndpoint() . $request['path']);
            $headers = [];
            foreach ($request['headers'] as $key => $value) {
                $headers[] = $key . ': ' . $value;
            }
            \curl_setopt_array($handle, [
                CURLOPT_CUSTOMREQUEST => $request['method'],
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => \json_encode($request['body']),
                CURLOPT_RETURNTRANSFER => true,
            ]);
            \curl_multi_add_handle($multi, $handle);
            $handles[] = $handle;
        }

        do {
            $status = \curl_multi_exec($multi, $running);
            \curl_multi_select($multi);
        } while ($running && $status === CURLM_OK);

        $statuses = [];
        foreach ($handles as $handle) {
            $statuses[] = \curl_getinfo($handle, CURLINFO_HTTP_CODE);
            \curl_multi_remove_handle($multi, $handle);
        }
        \curl_multi_close($multi);

        return $statuses;
    }

    /**
     * @return array<string, string>
     */
    private function getServerHeaders(array $project): array
    {
        return [
            'content-type' => 'application/json',
            'x-appwrite-project' => $project['$id'],
            'x-appwrite-key' => $project['apiKey'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function getGuestHeaders(array $project): array
    {
        return [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $project['$id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function getSessionHeaders(array $project, string $session): array
    {
        return \array_merge($this->getGuestHeaders($project), [
            'cookie' => 'a_session_' . $project['$id'] . '=' . $session,
        ]);
    }
}
