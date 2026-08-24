<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Project;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Helpers\ID;

/**
 * Sign-in flows against a real directory: the OpenLDAP fixture from
 * docker-compose.override.yml (profile `openldap`), seeded with
 * dev/openldap/seed.ldif. Configuration-only behavior that needs no
 * directory lives in LDAPBase.
 */
final class LDAPSessionIntegrationTest extends Scope
{
    use ProjectCustom;
    use SideServer;

    private const DIRECTORY_HOST = 'openldap';
    private const ALICE_DN = 'uid=alice,ou=people,dc=appwrite,dc=test';

    private static string $aliceId = '';

    public function testSignInDisabledByDefault(): void
    {
        $response = $this->signIn('alice', 'alicepass');

        $this->assertSame(501, $response['headers']['status-code']);
        $this->assertSame('user_auth_method_unsupported', $response['body']['type']);
    }

    public function testCreateSession(): void
    {
        $this->configureDirectory();

        $projectId = $this->getProject()['$id'];
        $response = $this->signIn('alice', 'alicepass');

        $this->assertSame(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertNotEmpty($response['body']['userId']);
        $this->assertSame('ldap', $response['body']['provider']);
        // The DN, not the typed username: stable across renames of display
        // attributes and unambiguous across the directory.
        $this->assertSame(self::ALICE_DN, $response['body']['providerUid']);
        $this->assertNotEmpty($response['cookies']['a_session_' . $projectId] ?? '');

        self::$aliceId = $response['body']['userId'];
        $session = $response['cookies']['a_session_' . $projectId];

        // The provisioned account carries the directory's values, with the
        // email trusted because the directory just authenticated against it.
        $account = $this->client->call(Client::METHOD_GET, '/account', $this->sessionHeaders($session));

        $this->assertSame(200, $account['headers']['status-code']);
        $this->assertSame(self::$aliceId, $account['body']['$id']);
        $this->assertSame('alice@appwrite.test', $account['body']['email']);
        $this->assertTrue($account['body']['emailVerification']);
        $this->assertSame('Alice Smith', $account['body']['name']);

        // The link to the directory entry is recorded, keyed by the DN.
        $identities = $this->client->call(Client::METHOD_GET, '/account/identities', $this->sessionHeaders($session));

        $this->assertSame(200, $identities['headers']['status-code']);
        $this->assertSame(1, $identities['body']['total']);
        $this->assertSame('ldap', $identities['body']['identities'][0]['provider']);
        $this->assertSame(self::ALICE_DN, $identities['body']['identities'][0]['providerUid']);
        $this->assertSame('alice@appwrite.test', $identities['body']['identities'][0]['providerEmail']);
    }

    public function testRepeatSignInReusesAccount(): void
    {
        $response = $this->signIn('alice', 'alicepass');

        $this->assertSame(201, $response['headers']['status-code']);
        $this->assertSame(self::$aliceId, $response['body']['userId']);
    }

    public function testDirectoryPasswordIsNotALocalPassword(): void
    {
        // The bind is the whole exchange: no local password is ever stored, so
        // the directory credentials must not work on the email/password route.
        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', $this->clientHeaders(), [
            'email' => 'alice@appwrite.test',
            'password' => 'alicepass',
        ]);

        $this->assertSame(401, $response['headers']['status-code']);
        $this->assertSame('user_invalid_credentials', $response['body']['type']);
    }

    public function testWrongPasswordIsRejected(): void
    {
        $response = $this->signIn('alice', 'wrongpass');

        $this->assertSame(401, $response['headers']['status-code']);
        $this->assertSame('user_invalid_credentials', $response['body']['type']);
    }

    public function testUnknownUserIsIndistinguishableFromWrongPassword(): void
    {
        // Telling an unknown user apart from a wrong password would let anyone
        // probe the directory for valid usernames.
        $unknownUser = $this->signIn('ghost', 'whatever');
        $wrongPassword = $this->signIn('alice', 'wrongpass');

        $this->assertSame(401, $unknownUser['headers']['status-code']);
        $this->assertSame($wrongPassword['body']['type'], $unknownUser['body']['type']);
        $this->assertSame($wrongPassword['body']['message'], $unknownUser['body']['message']);
    }

    public function testEmptyPasswordIsRejected(): void
    {
        // A directory accepts an empty password as an "unauthenticated bind"
        // and reports success, so it must be refused before it reaches one.
        $response = $this->signIn('alice', '');

        $this->assertSame(401, $response['headers']['status-code']);
        $this->assertSame('user_invalid_credentials', $response['body']['type']);
    }

    public function testProvisionGroupRestrictsAccounts(): void
    {
        $this->configureDirectory([
            'provisionGroupDn' => 'cn=appwrite-users,ou=groups,dc=appwrite,dc=test',
        ]);

        // Valid credentials, but not in the group: refused, and refused with
        // the same response as a wrong password.
        $response = $this->signIn('bob', 'bobpass');

        $this->assertSame(401, $response['headers']['status-code']);
        $this->assertSame('user_invalid_credentials', $response['body']['type']);

        // In the group, recorded with her exact DN.
        $response = $this->signIn('alice', 'alicepass');

        $this->assertSame(201, $response['headers']['status-code']);
        $this->assertSame(self::$aliceId, $response['body']['userId']);
    }

    public function testProvisionGroupMatchesDnAcrossCaseAndSpacing(): void
    {
        // The appwrite-users-cased group records Alice's DN with different
        // case and spacing than her entry. The directory treats both as the
        // same entry, so the membership check must admit her: this exercises
        // the component-wise DN comparison rather than the byte-exact one.
        $this->configureDirectory([
            'provisionGroupDn' => 'cn=appwrite-users-cased,ou=groups,dc=appwrite,dc=test',
        ]);

        $response = $this->signIn('alice', 'alicepass');

        $this->assertSame(201, $response['headers']['status-code']);
        $this->assertSame(self::$aliceId, $response['body']['userId']);
    }

    public function testEntryWithoutEmailIsRejected(): void
    {
        $this->configureDirectory();

        // Valid credentials, but no mail attribute: an account cannot exist
        // without an email address. Reported only after the bind proved the
        // password, so it reveals nothing to anyone else.
        $response = $this->signIn('noemail', 'noemailpass');

        $this->assertSame(401, $response['headers']['status-code']);
        $this->assertSame('user_unauthorized', $response['body']['type']);
    }

    public function testEntryWithMalformedEmailIsRejected(): void
    {
        $response = $this->signIn('bademail', 'bademailpass');

        $this->assertSame(401, $response['headers']['status-code']);
        $this->assertSame('user_unauthorized', $response['body']['type']);
    }

    public function testExistingUnlinkedAccountIsNotAdopted(): void
    {
        // An account that merely shares the address must not be signed into:
        // linking a local account to a directory is a deliberate act.
        $user = $this->client->call(Client::METHOD_POST, '/users', $this->serverHeaders(), [
            'userId' => ID::unique(),
            'email' => 'carol@appwrite.test',
            'password' => 'localpassword',
        ]);

        $this->assertSame(201, $user['headers']['status-code']);

        $response = $this->signIn('carol', 'carolpass');

        $this->assertSame(409, $response['headers']['status-code']);
        $this->assertSame('user_already_exists', $response['body']['type']);
    }

    public function testBlockedUserIsRejected(): void
    {
        $response = $this->signIn('dave', 'davepass');

        $this->assertSame(201, $response['headers']['status-code']);
        $daveId = $response['body']['userId'];

        $blocked = $this->client->call(Client::METHOD_PATCH, '/users/' . $daveId . '/status', $this->serverHeaders(), [
            'status' => false,
        ]);

        $this->assertSame(200, $blocked['headers']['status-code']);

        // Valid directory credentials no longer help a blocked account.
        $response = $this->signIn('dave', 'davepass');

        $this->assertSame(401, $response['headers']['status-code']);
        $this->assertSame('user_blocked', $response['body']['type']);
    }

    public function testGenericToggleGatesSignIn(): void
    {
        $response = $this->client->call(Client::METHOD_PATCH, '/project/auth-methods/ldap', $this->serverHeaders(), [
            'enabled' => false,
        ]);

        $this->assertSame(200, $response['headers']['status-code']);

        $response = $this->signIn('alice', 'alicepass');

        $this->assertSame(501, $response['headers']['status-code']);
        $this->assertSame('user_auth_method_unsupported', $response['body']['type']);

        $response = $this->client->call(Client::METHOD_PATCH, '/project/auth-methods/ldap', $this->serverHeaders(), [
            'enabled' => true,
        ]);

        $this->assertSame(200, $response['headers']['status-code']);

        $response = $this->signIn('alice', 'alicepass');

        $this->assertSame(201, $response['headers']['status-code']);
    }

    // Helpers

    /**
     * Point the project at the seeded OpenLDAP fixture and enable sign-in.
     * Enabling connects and binds, so a passing call also proves the fixture
     * is up. Passing no overrides resets the provisioning filter.
     */
    private function configureDirectory(array $overrides = []): void
    {
        $response = $this->client->call(Client::METHOD_PATCH, '/project/auth/ldap', $this->serverHeaders(), \array_merge([
            'host' => self::DIRECTORY_HOST,
            'port' => 389,
            'encryption' => 'none',
            'baseDn' => 'dc=appwrite,dc=test',
            'bindDn' => 'cn=admin,dc=appwrite,dc=test',
            'bindPassword' => 'adminpassword',
            'userFilter' => '(uid={{username}})',
            'provisionGroupDn' => '',
            'emailAttribute' => 'mail',
            'nameAttribute' => 'cn',
            'enabled' => true,
        ], $overrides));

        $this->assertSame(200, $response['headers']['status-code'], 'Could not configure the LDAP fixture: ' . \json_encode($response['body']));
        $this->assertTrue($response['body']['enabled']);
    }

    private function signIn(string $username, string $password): array
    {
        return $this->client->call(Client::METHOD_POST, '/account/sessions/ldap', $this->clientHeaders(), [
            'username' => $username,
            'password' => $password,
        ]);
    }

    private function serverHeaders(): array
    {
        return [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];
    }

    private function clientHeaders(): array
    {
        return [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];
    }

    private function sessionHeaders(string $session): array
    {
        return \array_merge($this->clientHeaders(), [
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]);
    }
}
