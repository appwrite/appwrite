<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Account;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectConsole;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;
use Utopia\Auth\Passkeys\Origin;
use Utopia\Auth\Tests\Passkeys\Authenticator;
use Utopia\Database\Id;

final class AccountConsolePasskeysTest extends Scope
{
    use ProjectConsole;
    use SideClient;

    private const array GUEST = [
        'origin' => 'http://localhost',
        'content-type' => 'application/json',
        'x-appwrite-project' => 'console',
    ];

    public function testConsoleSignInWithPasskey(): void
    {
        [$email, $cookie] = $this->createConsoleSession();
        $session = self::GUEST + ['cookie' => 'a_session_console=' . $cookie];

        /**
         * Test for SUCCESS
         */
        $authenticator = new Authenticator();
        $challenge = $this->client->call(Client::METHOD_POST, '/account/passkeys', $session, ['name' => 'Laptop']);
        $this->assertSame(201, $challenge['headers']['status-code'], \json_encode($challenge['body']));
        $this->assertSame($email, $challenge['body']['publicKey']['user']['name']);

        // The console's relying party is its own host, and that host is an allowed origin
        $rpId = $challenge['body']['publicKey']['rp']['id'];
        $origin = ($rpId === Origin::LOCALHOST ? 'http://' : 'https://') . $rpId;

        $passkey = $this->client->call(Client::METHOD_PUT, '/account/passkeys/' . $challenge['body']['passkeyId'] . '/verification', $session, [
            'credential' => $authenticator->register($challenge['body']['publicKey'], $origin),
        ]);
        $this->assertSame(200, $passkey['headers']['status-code'], \json_encode($passkey['body']));

        $token = $this->signIn($authenticator, $origin);
        $this->assertSame(201, $token['headers']['status-code'], \json_encode($token['body']));

        $signedIn = $this->client->call(Client::METHOD_POST, '/account/sessions/token', self::GUEST, [
            'userId' => $token['body']['userId'],
            'secret' => $token['body']['secret'],
        ]);
        $this->assertSame(201, $signedIn['headers']['status-code']);
        $this->assertSame('passkey', $signedIn['body']['provider']);

        $account = $this->client->call(Client::METHOD_GET, '/account', self::GUEST + [
            'cookie' => 'a_session_console=' . $signedIn['cookies']['a_session_console'],
        ]);
        $this->assertSame(200, $account['headers']['status-code']);
        $this->assertSame($email, $account['body']['email']);

        /**
         * Test for FAILURE
         */
        // Only the console's own origins may use console passkeys
        $this->assertSame(401, $this->signIn($authenticator, 'https://evil.example.com')['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_DELETE, '/account/passkeys/' . $passkey['body']['$id'], $session);
        $this->assertSame(204, $response['headers']['status-code']);
        $this->assertSame(401, $this->signIn($authenticator, $origin)['headers']['status-code']);
    }

    /**
     * @return array<string, mixed>
     */
    private function signIn(Authenticator $authenticator, string $origin): array
    {
        $challenge = $this->client->call(Client::METHOD_POST, '/account/tokens/passkey', self::GUEST);
        $this->assertSame(201, $challenge['headers']['status-code'], \json_encode($challenge['body']));

        return $this->client->call(Client::METHOD_PUT, '/account/tokens/passkey', self::GUEST, [
            'challengeId' => $challenge['body']['$id'],
            'credential' => $authenticator->authenticate($challenge['body']['publicKey'], $origin),
        ]);
    }

    /**
     * @return array{string, string} email and session cookie of a fresh console account
     */
    private function createConsoleSession(): array
    {
        $email = \uniqid('passkey', true) . \bin2hex(\random_bytes(4)) . '@appwrite.io';

        $user = $this->client->call(Client::METHOD_POST, '/account', self::GUEST, [
            'userId' => Id::unique(),
            'email' => $email,
            'password' => 'password',
            'name' => 'Passkey User',
        ]);
        $this->assertSame(201, $user['headers']['status-code']);

        $session = $this->client->call(Client::METHOD_POST, '/account/sessions/email', self::GUEST, [
            'email' => $email,
            'password' => 'password',
        ]);
        $this->assertSame(201, $session['headers']['status-code']);

        return [$email, $session['cookies']['a_session_console']];
    }
}
