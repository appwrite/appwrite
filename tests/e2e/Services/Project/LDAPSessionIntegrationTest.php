<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Project;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Id;
use Utopia\Database\Query;

/**
 * Sign-in flows against a real directory: the OpenLDAP fixture from
 * docker-compose.override.yml (profile `openldap`), seeded with
 * dev/openldap/seed.ldif and serving TLS with the certificates from
 * dev/openldap/certs.sh. Configuration-only behaviour that needs no
 * directory lives in LDAPBase.
 */
final class LDAPSessionIntegrationTest extends Scope
{
    use ProjectCustom;
    use SideServer;

    private const string HOST = 'openldap';
    private const string BASE_DN = 'dc=appwrite,dc=test';
    private const string BIND_DN = 'cn=admin,dc=appwrite,dc=test';
    private const string BIND_PASSWORD = 'adminpassword';
    private const string CA_CERTIFICATE = __DIR__ . '/../../../../dev/openldap/certs/ca.crt';

    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    protected function setUp(): void
    {
        parent::setUp();

        // `paratest --functional` hands these methods to its workers in any order, and a worker
        // keeps its statics between them. Each test gets a project of its own, so none depends
        // on another having configured the directory or signed someone in first.
        self::$project = [];
    }

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
        $this->assertSame(['password'], $response['body']['factors']);
        // The entry's permanent identifier, not the typed username or the DN:
        // it survives renames and moves within the directory.
        $this->assertMatchesRegularExpression(self::UUID, $response['body']['providerUid']);
        $this->assertNotEmpty($response['cookies']['a_session_' . $projectId] ?? '');

        $aliceId = $response['body']['userId'];
        $aliceUid = $response['body']['providerUid'];
        $session = $response['cookies']['a_session_' . $projectId];

        // The provisioned account carries the directory's values, with the
        // email trusted because the directory is the project's authority on it.
        $account = $this->client->call(Client::METHOD_GET, '/account', $this->sessionHeaders($session));

        $this->assertSame(200, $account['headers']['status-code']);
        $this->assertSame($aliceId, $account['body']['$id']);
        $this->assertSame('alice@appwrite.test', $account['body']['email']);
        $this->assertTrue($account['body']['emailVerification']);
        $this->assertSame('Alice Smith', $account['body']['name']);
        $this->assertSame('', $account['body']['phone']);

        // The link to the directory entry is recorded under the same identifier.
        $identities = $this->client->call(Client::METHOD_GET, '/account/identities', $this->sessionHeaders($session));

        $this->assertSame(200, $identities['headers']['status-code']);
        $this->assertSame(1, $identities['body']['total']);
        $this->assertSame('ldap', $identities['body']['identities'][0]['provider']);
        $this->assertSame($aliceUid, $identities['body']['identities'][0]['providerUid']);
        $this->assertSame('alice@appwrite.test', $identities['body']['identities'][0]['providerEmail']);

        // Provisioning also made the address reachable through messaging.
        $targets = $this->client->call(Client::METHOD_GET, '/users/' . $aliceId . '/targets', $this->serverHeaders());

        $this->assertSame(200, $targets['headers']['status-code']);
        $this->assertSame(1, $targets['body']['total']);
        $this->assertSame('email', $targets['body']['targets'][0]['providerType']);
        $this->assertSame('alice@appwrite.test', $targets['body']['targets'][0]['identifier']);
    }

    public function testRepeatSignInReusesAccount(): void
    {
        $alice = $this->signInAlice();

        $response = $this->signIn('alice', 'alicepass');

        $this->assertSame(201, $response['headers']['status-code']);
        $this->assertSame($alice['userId'], $response['body']['userId']);
        $this->assertSame($alice['providerUid'], $response['body']['providerUid']);
    }

    public function testSessionDuration(): void
    {
        $this->configureDirectory();

        $response = $this->signIn('alice', 'alicepass', ['duration' => 60]);

        $this->assertSame(201, $response['headers']['status-code']);
        $this->assertLessThanOrEqual(\time() + 70, (new \DateTime($response['body']['expire']))->getTimestamp());

        $response = $this->signIn('alice', 'alicepass', ['duration' => 30]);

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $response['body']['type']);
    }

    public function testDirectoryPasswordIsNotALocalPassword(): void
    {
        $this->signInAlice();

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
        $this->configureDirectory();

        $response = $this->signIn('alice', 'wrongpass');

        $this->assertSame(401, $response['headers']['status-code']);
        $this->assertSame('user_invalid_credentials', $response['body']['type']);
    }

    public function testUnknownUserIsIndistinguishableFromWrongPassword(): void
    {
        $this->configureDirectory();

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
        $this->configureDirectory();

        // A directory accepts an empty password as an "unauthenticated bind"
        // and reports success, so it never reaches one: the parameter is refused.
        $response = $this->signIn('alice', '');

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $response['body']['type']);
    }

    public function testFilterInjectionIsNotPossible(): void
    {
        $this->configureDirectory();

        // The username is carried as an assertion value, so filter syntax in it
        // matches nothing instead of matching everything.
        foreach (['*', 'ali*', 'alice)(uid=*', '\\2a'] as $username) {
            $response = $this->signIn($username, 'alicepass');

            $this->assertSame(401, $response['headers']['status-code'], 'Username: ' . $username);
            $this->assertSame('user_invalid_credentials', $response['body']['type']);
        }
    }

    public function testAmbiguousUsernameIsRejected(): void
    {
        $alice = $this->signInAlice();

        // Two entries share cn=Twin. Binding as an arbitrary one of them would
        // be a coin toss over identity, so neither is signed in.
        $this->configureDirectory([
            'usernameAttribute' => 'cn',
        ]);

        $response = $this->signIn('Twin', 'twinpass');

        $this->assertSame(401, $response['headers']['status-code']);
        $this->assertSame('user_invalid_credentials', $response['body']['type']);

        $response = $this->signIn('Alice Smith', 'alicepass');

        $this->assertSame(201, $response['headers']['status-code']);
        $this->assertSame($alice['userId'], $response['body']['userId']);
    }

    public function testUserFilterRestrictsEntries(): void
    {
        $this->configureDirectory([
            'userFilter' => '(sn=Smith)',
        ]);

        $response = $this->signIn('alice', 'alicepass');

        $this->assertSame(201, $response['headers']['status-code']);

        $response = $this->signIn('bob', 'bobpass');

        $this->assertSame(401, $response['headers']['status-code']);
        $this->assertSame('user_invalid_credentials', $response['body']['type']);

        // A filter that does not parse cannot be enabled.
        $response = $this->updateLDAP([
            'userFilter' => '(sn=Smith',
        ]);

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('project_ldap_config_invalid', $response['body']['type']);
        $this->assertStringContainsString('(sn=Smith', (string) $response['body']['message']);
    }

    public function testRequiredGroupRestrictsAccounts(): void
    {
        $alice = $this->signInAlice();

        // Membership recorded on the group, groupOfNames style.
        $this->configureDirectory([
            'requiredGroupDn' => 'cn=appwrite-users,ou=groups,dc=appwrite,dc=test',
        ]);

        // Valid credentials, but not in the group: refused, and refused with
        // the same response as a wrong password.
        $response = $this->signIn('bob', 'bobpass');

        $this->assertSame(401, $response['headers']['status-code']);
        $this->assertSame('user_invalid_credentials', $response['body']['type']);

        $response = $this->signIn('alice', 'alicepass');

        $this->assertSame(201, $response['headers']['status-code']);
        $this->assertSame($alice['userId'], $response['body']['userId']);
    }

    public function testRequiredGroupMatchesDnAcrossCaseAndSpacing(): void
    {
        $alice = $this->signInAlice();

        // The appwrite-users-cased group records Alice's DN with different case
        // and spacing than her entry. The directory compares the DNs, so she is
        // a member either way.
        $this->configureDirectory([
            'requiredGroupDn' => 'cn=appwrite-users-cased,ou=groups,dc=appwrite,dc=test',
        ]);

        $response = $this->signIn('alice', 'alicepass');

        $this->assertSame(201, $response['headers']['status-code']);
        $this->assertSame($alice['userId'], $response['body']['userId']);
    }

    public function testRequiredGroupOfUniqueNames(): void
    {
        // groupOfUniqueNames, which the fixture's memberOf overlay also mirrors
        // onto the user entry the way Active Directory records membership.
        $this->configureDirectory([
            'requiredGroupDn' => 'cn=appwrite-unique,ou=groups,dc=appwrite,dc=test',
        ]);

        $response = $this->signIn('alice', 'alicepass');

        $this->assertSame(201, $response['headers']['status-code']);

        $response = $this->signIn('bob', 'bobpass');

        $this->assertSame(401, $response['headers']['status-code']);
    }

    public function testRequiredGroupPosix(): void
    {
        // posixGroup lists member usernames rather than DNs.
        $this->configureDirectory([
            'requiredGroupDn' => 'cn=appwrite-posix,ou=groups,dc=appwrite,dc=test',
        ]);

        $response = $this->signIn('alice', 'alicepass');

        $this->assertSame(201, $response['headers']['status-code']);

        $response = $this->signIn('bob', 'bobpass');

        $this->assertSame(401, $response['headers']['status-code']);
    }

    public function testRequiredGroupPosixMatchesTheEntryUid(): void
    {
        $alice = $this->signInAlice();

        // memberUid holds the uid the member's entry stores, compared case-exactly, so the
        // check reads it from the entry rather than using what the user typed.
        $this->configureDirectory([
            'requiredGroupDn' => 'cn=appwrite-posix,ou=groups,dc=appwrite,dc=test',
        ]);

        $response = $this->signIn('ALICE', 'alicepass');

        $this->assertSame(201, $response['headers']['status-code']);
        $this->assertSame($alice['userId'], $response['body']['userId']);

        // The same holds when users sign in with another attribute than their uid.
        $this->configureDirectory([
            'usernameAttribute' => 'mail',
            'requiredGroupDn' => 'cn=appwrite-posix,ou=groups,dc=appwrite,dc=test',
        ]);

        $response = $this->signIn('alice@appwrite.test', 'alicepass');

        $this->assertSame(201, $response['headers']['status-code']);
        $this->assertSame($alice['userId'], $response['body']['userId']);

        $response = $this->signIn('bob@appwrite.test', 'bobpass');

        $this->assertSame(401, $response['headers']['status-code']);
    }

    public function testRequiredGroupMissingIsDirectoryError(): void
    {
        // A group that does not exist is a configuration error, not a failed
        // sign-in, and it must not admit anyone either.
        $this->configureDirectory([
            'requiredGroupDn' => 'cn=missing,ou=groups,dc=appwrite,dc=test',
        ]);

        $response = $this->signIn('alice', 'alicepass');

        $this->assertSame(424, $response['headers']['status-code']);
        $this->assertSame('user_ldap_directory_error', $response['body']['type']);

        $response = $this->createLDAPTest(['username' => 'alice']);

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('project_ldap_config_invalid', $response['body']['type']);
        $this->assertStringContainsString('cn=missing,ou=groups,dc=appwrite,dc=test', (string) $response['body']['message']);
    }

    public function testEntryWithoutEmailCreatesUserWithoutEmail(): void
    {
        $this->configureDirectory();

        // Valid credentials and no mail attribute: the account exists without
        // an address, as it would after a native OAuth2 sign-in without one.
        $response = $this->signIn('noemail', 'noemailpass');

        $this->assertSame(201, $response['headers']['status-code']);

        $user = $this->client->call(Client::METHOD_GET, '/users/' . $response['body']['userId'], $this->serverHeaders());

        $this->assertSame(200, $user['headers']['status-code']);
        $this->assertSame('', $user['body']['email']);
        $this->assertFalse($user['body']['emailVerification']);
        $this->assertSame('No Email', $user['body']['name']);
    }

    public function testEntryWithMalformedEmailIsRejected(): void
    {
        $this->configureDirectory();

        $response = $this->signIn('bademail', 'bademailpass');

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('general_invalid_email', $response['body']['type']);
    }

    public function testExistingVerifiedAccountIsAdopted(): void
    {
        $this->configureDirectory();

        // An account that already owns the verified address is the same person,
        // so the directory sign-in links to it rather than creating a duplicate,
        // as an OAuth2 sign-in with an attested email does.
        $user = $this->client->call(Client::METHOD_POST, '/users', $this->serverHeaders(), [
            'userId' => Id::unique(),
            'email' => 'carol@appwrite.test',
            'password' => 'localpassword',
            'name' => 'Carol',
        ]);

        $this->assertSame(201, $user['headers']['status-code']);

        $verified = $this->client->call(Client::METHOD_PATCH, '/users/' . $user['body']['$id'] . '/verification', $this->serverHeaders(), [
            'emailVerification' => true,
        ]);

        $this->assertSame(200, $verified['headers']['status-code']);

        $response = $this->signIn('carol', 'carolpass');

        $this->assertSame(201, $response['headers']['status-code']);
        $this->assertSame($user['body']['$id'], $response['body']['userId']);

        // Linked, and the name now follows the directory.
        $identities = $this->identitiesOf($user['body']['$id']);

        $this->assertSame(200, $identities['headers']['status-code']);
        $this->assertSame(1, $identities['body']['total']);
        $this->assertSame('ldap', $identities['body']['identities'][0]['provider']);

        $account = $this->client->call(Client::METHOD_GET, '/users/' . $user['body']['$id'], $this->serverHeaders());

        $this->assertSame('Carol White', $account['body']['name']);
    }

    public function testBlockedUserIsRejected(): void
    {
        $this->configureDirectory();

        $response = $this->signIn('dave', 'davepass');

        $this->assertSame(201, $response['headers']['status-code']);
        $daveId = $response['body']['userId'];

        $blocked = $this->client->call(Client::METHOD_PATCH, '/users/' . $daveId . '/status', $this->serverHeaders(), [
            'status' => false,
        ]);

        $this->assertSame(200, $blocked['headers']['status-code']);

        // Valid directory credentials no longer help a blocked account: the
        // same blocked-account response every other sign-in path gives.
        $response = $this->signIn('dave', 'davepass');

        $this->assertSame(403, $response['headers']['status-code']);
        $this->assertSame('user_blocked', $response['body']['type']);
    }

    public function testAccountFollowsDirectory(): void
    {
        $alice = $this->signInAlice();

        // The directory is the authority on who the user is: a different
        // mapping stands in for the entry changing, and each sign-in applies it.
        $this->configureDirectory([
            'nameAttribute' => 'givenName',
            'emailAttribute' => 'description',
            'phoneAttribute' => 'mobile',
        ]);

        $response = $this->signIn('alice', 'alicepass');

        $this->assertSame(201, $response['headers']['status-code']);
        $this->assertSame($alice['userId'], $response['body']['userId']);

        $user = $this->client->call(Client::METHOD_GET, '/users/' . $alice['userId'], $this->serverHeaders());

        $this->assertSame('Alice', $user['body']['name']);
        $this->assertSame('alice.alt@appwrite.test', $user['body']['email']);
        $this->assertTrue($user['body']['emailVerification']);
        $this->assertSame('+15550000001', $user['body']['phone']);
        $this->assertFalse($user['body']['phoneVerification']);

        // The messaging targets moved with the address and the number.
        $targets = $this->client->call(Client::METHOD_GET, '/users/' . $alice['userId'] . '/targets', $this->serverHeaders());

        $identifiers = \array_column($targets['body']['targets'], 'identifier', 'providerType');
        $this->assertSame('alice.alt@appwrite.test', $identifiers['email'] ?? null);
        $this->assertSame('+15550000001', $identifiers['sms'] ?? null);

        // A number the directory holds in a format Appwrite cannot message is left alone.
        $this->configureDirectory([
            'phoneAttribute' => 'telephoneNumber',
        ]);

        $response = $this->signIn('alice', 'alicepass');

        $this->assertSame(201, $response['headers']['status-code']);

        $user = $this->client->call(Client::METHOD_GET, '/users/' . $alice['userId'], $this->serverHeaders());

        $this->assertSame('Alice Smith', $user['body']['name']);
        $this->assertSame('alice@appwrite.test', $user['body']['email']);
        $this->assertSame('+15550000001', $user['body']['phone']);

        $identities = $this->identitiesOf($alice['userId']);

        $this->assertSame(1, $identities['body']['total']);
        $this->assertSame('alice@appwrite.test', $identities['body']['identities'][0]['providerEmail']);
    }

    public function testAccountKeepsItsAddressWhenTheDirectoryOneIsTaken(): void
    {
        // Another account already holds the address the directory now lists. The user
        // still signs in and keeps their address: refusing would lock them out over
        // someone else's account and tell the caller that the address has one.
        $other = $this->client->call(Client::METHOD_POST, '/users', $this->serverHeaders(), [
            'userId' => Id::unique(),
            'email' => 'alice.alt@appwrite.test',
            'password' => 'otherpassword',
        ]);

        $this->assertSame(201, $other['headers']['status-code']);

        $alice = $this->signInAlice();

        $this->configureDirectory([
            'emailAttribute' => 'description',
        ]);

        $response = $this->signIn('alice', 'alicepass');

        $this->assertSame(201, $response['headers']['status-code']);
        $this->assertSame($alice['userId'], $response['body']['userId']);

        $user = $this->client->call(Client::METHOD_GET, '/users/' . $alice['userId'], $this->serverHeaders());

        $this->assertSame('alice@appwrite.test', $user['body']['email']);

        // The identity records the address the account holds, not the one it could not take.
        $identities = $this->identitiesOf($alice['userId']);

        $this->assertSame(1, $identities['body']['total']);
        $this->assertSame('alice@appwrite.test', $identities['body']['identities'][0]['providerEmail']);

        $deleted = $this->client->call(Client::METHOD_DELETE, '/users/' . $other['body']['$id'], $this->serverHeaders());

        $this->assertSame(204, $deleted['headers']['status-code']);
    }

    public function testSignInJoinsAccountSwitchingDevice(): void
    {
        $account = $this->signInAlice();

        $projectId = $this->getProject()['$id'];
        $deviceKey = 'a_device_' . $projectId;
        $sessionKey = 'a_session_' . $projectId;

        $device = $this->client->call(Client::METHOD_POST, '/account/devices', $this->clientHeaders());

        $this->assertSame(201, $device['headers']['status-code']);

        $deviceCookie = $device['cookies'][$deviceKey];

        /**
         * Test for SUCCESS - the session joins the device and becomes its active one
         */
        $alice = $this->signIn('alice', 'alicepass', headers: ['cookie' => $deviceKey . '=' . $deviceCookie]);

        $this->assertSame(201, $alice['headers']['status-code']);
        $this->assertSame($account['userId'], $alice['body']['userId']);
        $this->assertSame('Alice Smith', $alice['body']['userName']);

        // Joining a session rotates the device secret
        $deviceCookie = $alice['cookies'][$deviceKey];

        $current = $this->getDevice($deviceKey . '=' . $deviceCookie);

        $this->assertSame(200, $current['headers']['status-code']);
        $this->assertCount(1, $current['body']['sessions']);
        $this->assertSame($alice['body']['$id'], $current['body']['activeSessionId']);

        /**
         * Test for SUCCESS - with Alice signed in, another directory user signs in on the
         * same device: their own account joins it, and Alice's is not linked to their entry
         */
        $bob = $this->signIn('bob', 'bobpass', headers: ['cookie' => $sessionKey . '=' . $alice['cookies'][$sessionKey] . '; ' . $deviceKey . '=' . $deviceCookie]);

        $this->assertSame(201, $bob['headers']['status-code']);
        $this->assertNotSame($account['userId'], $bob['body']['userId']);
        $this->assertSame('Bob Jones', $bob['body']['userName']);

        $deviceCookie = $bob['cookies'][$deviceKey];

        $current = $this->getDevice($deviceKey . '=' . $deviceCookie);

        $this->assertCount(2, $current['body']['sessions']);
        $this->assertSame($bob['body']['$id'], $current['body']['activeSessionId']);

        $user = $this->client->call(Client::METHOD_GET, '/users/' . $account['userId'], $this->serverHeaders());

        $this->assertSame('Alice Smith', $user['body']['name']);
        $this->assertSame('alice@appwrite.test', $user['body']['email']);

        $identities = $this->identitiesOf($account['userId']);

        $this->assertSame(1, $identities['body']['total']);
        $this->assertSame($account['providerUid'], $identities['body']['identities'][0]['providerUid']);

        $identities = $this->identitiesOf($bob['body']['userId']);

        $this->assertSame(1, $identities['body']['total']);
        $this->assertSame('ldap', $identities['body']['identities'][0]['provider']);
        $this->assertSame('bob@appwrite.test', $identities['body']['identities'][0]['providerEmail']);
    }

    public function testEmailPolicyApplies(): void
    {
        $this->configureDirectory();

        $policy = $this->client->call(Client::METHOD_PATCH, '/project/policies/deny-disposable-email', $this->serverHeaders(), [
            'enabled' => true,
        ]);

        if (($policy['body']['type'] ?? null) === 'general_access_forbidden') {
            $this->markTestSkipped('This plan does not support the deny-disposable-email policy.');
        }

        $this->assertSame(200, $policy['headers']['status-code']);

        try {
            $response = $this->signIn('erin', 'erinpass');

            $this->assertSame(400, $response['headers']['status-code']);
            $this->assertSame('user_email_disposable', $response['body']['type']);
        } finally {
            $this->client->call(Client::METHOD_PATCH, '/project/policies/deny-disposable-email', $this->serverHeaders(), [
                'enabled' => false,
            ]);
        }
    }

    public function testUserLimitApplies(): void
    {
        $this->signInAlice();

        $policy = $this->client->call(Client::METHOD_PATCH, '/project/policies/user-limit', $this->serverHeaders(), [
            'total' => 1,
        ]);

        $this->assertSame(200, $policy['headers']['status-code']);

        try {
            // An existing account still signs in; a new one would exceed the limit.
            $response = $this->signIn('alice', 'alicepass');

            $this->assertSame(201, $response['headers']['status-code']);

            $response = $this->signIn('frank', 'frankpass');

            $this->assertSame(400, $response['headers']['status-code']);
            $this->assertSame('user_count_exceeded', $response['body']['type']);
        } finally {
            $this->client->call(Client::METHOD_PATCH, '/project/policies/user-limit', $this->serverHeaders(), [
                'total' => 0,
            ]);
        }
    }

    public function testFailoverToNextHost(): void
    {
        // Nothing listens on loopback, so the first host refuses and the
        // second one answers: enabling and signing in both use it.
        $this->configureDirectory([
            'hosts' => ['127.0.0.1', self::HOST],
        ]);

        $response = $this->signIn('alice', 'alicepass');

        $this->assertSame(201, $response['headers']['status-code']);
    }

    public function testDirectoryDownIsDirectoryError(): void
    {
        // Stored while disabled, which never connects, then switched on through
        // the generic toggle, which does not connect either.
        $this->configureDirectory([
            'hosts' => ['127.0.0.1'],
            'port' => 1,
            'enabled' => false,
        ]);

        $response = $this->updateAuthMethod(true);

        $this->assertSame(200, $response['headers']['status-code']);

        $response = $this->signIn('alice', 'alicepass');

        $this->assertSame(424, $response['headers']['status-code']);
        $this->assertSame('user_ldap_directory_error', $response['body']['type']);
    }

    public function testServiceBindFailureIsDirectoryError(): void
    {
        $this->configureDirectory([
            'bindPassword' => 'wrongpassword',
            'enabled' => false,
        ]);

        $response = $this->updateAuthMethod(true);

        $this->assertSame(200, $response['headers']['status-code']);

        // Not the user's fault, and not a 500: the directory refused Appwrite.
        $response = $this->signIn('alice', 'alicepass');

        $this->assertSame(424, $response['headers']['status-code']);
        $this->assertSame('user_ldap_directory_error', $response['body']['type']);

        $response = $this->createLDAPTest();

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('project_ldap_config_invalid', $response['body']['type']);
        $this->assertStringContainsString('service account', (string) $response['body']['message']);
    }

    public function testCreateLDAPTest(): void
    {
        $alice = $this->signInAlice();

        $this->configureDirectory([
            'requiredGroupDn' => 'cn=appwrite-users,ou=groups,dc=appwrite,dc=test',
        ]);

        // Connection and service account only.
        $response = $this->createLDAPTest();

        $this->assertSame(204, $response['headers']['status-code']);

        // The whole sign-in, without a session.
        $before = $this->client->call(Client::METHOD_GET, '/users/' . $alice['userId'] . '/sessions', $this->serverHeaders());

        $response = $this->createLDAPTest(['username' => 'alice', 'password' => 'alicepass']);

        $this->assertSame(204, $response['headers']['status-code']);

        $after = $this->client->call(Client::METHOD_GET, '/users/' . $alice['userId'] . '/sessions', $this->serverHeaders());

        $this->assertSame(200, $after['headers']['status-code']);
        $this->assertSame($before['body']['total'], $after['body']['total']);

        // Each step names itself when it fails.
        $response = $this->createLDAPTest(['username' => 'alice', 'password' => 'wrongpass']);

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('project_ldap_config_invalid', $response['body']['type']);
        $this->assertStringContainsString('refused the password', (string) $response['body']['message']);

        $response = $this->createLDAPTest(['username' => 'ghost']);

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertStringContainsString('No entry', (string) $response['body']['message']);

        $response = $this->createLDAPTest(['username' => 'bob']);

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertStringContainsString('not a member', (string) $response['body']['message']);

        // A directory nobody can reach is reported with the host that was dialled.
        $response = $this->updateLDAP([
            'hosts' => ['127.0.0.1'],
            'port' => 1,
            'bindPassword' => self::BIND_PASSWORD,
            'enabled' => false,
        ]);

        $this->assertSame(200, $response['headers']['status-code']);

        $response = $this->createLDAPTest();

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertStringContainsString('127.0.0.1:1', (string) $response['body']['message']);
    }

    public function testEncryptionLdapsWithCaCertificate(): void
    {
        $alice = $this->signInAlice();

        $this->configureDirectory([
            'encryption' => 'ldaps',
            'port' => 636,
            'tls' => true,
            'caCertificate' => $this->caCertificate(),
        ]);

        $response = $this->signIn('alice', 'alicepass');

        $this->assertSame(201, $response['headers']['status-code']);
        $this->assertSame($alice['userId'], $response['body']['userId']);
    }

    public function testEncryptionLdapsRequiresTrust(): void
    {
        $this->configureDirectory();

        // The fixture certificate is not signed by anything in the system
        // trust store, so verification refuses it without the CA.
        $this->caCertificate();

        $response = $this->updateLDAP([
            'encryption' => 'ldaps',
            'port' => 636,
            'tls' => true,
            'caCertificate' => '',
            'bindPassword' => self::BIND_PASSWORD,
            'enabled' => true,
        ]);

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('project_ldap_config_invalid', $response['body']['type']);
        $this->assertStringContainsString(self::HOST . ':636', (string) $response['body']['message']);
    }

    public function testEncryptionLdapsWithoutVerification(): void
    {
        $this->caCertificate();

        $this->configureDirectory([
            'encryption' => 'ldaps',
            'port' => 636,
            'tls' => false,
            'caCertificate' => '',
        ]);

        $response = $this->signIn('alice', 'alicepass');

        $this->assertSame(201, $response['headers']['status-code']);
    }

    public function testEncryptionStartTls(): void
    {
        $alice = $this->signInAlice();

        $this->configureDirectory([
            'encryption' => 'starttls',
            'port' => 389,
            'tls' => true,
            'caCertificate' => $this->caCertificate(),
        ]);

        $response = $this->signIn('alice', 'alicepass');

        $this->assertSame(201, $response['headers']['status-code']);
        $this->assertSame($alice['userId'], $response['body']['userId']);

        $response = $this->createLDAPTest(['username' => 'alice', 'password' => 'alicepass']);

        $this->assertSame(204, $response['headers']['status-code']);
    }

    public function testGenericToggleGatesSignIn(): void
    {
        $this->configureDirectory();

        $response = $this->updateAuthMethod(false);

        $this->assertSame(200, $response['headers']['status-code']);

        $response = $this->signIn('alice', 'alicepass');

        $this->assertSame(501, $response['headers']['status-code']);
        $this->assertSame('user_auth_method_unsupported', $response['body']['type']);

        $response = $this->updateAuthMethod(true);

        $this->assertSame(200, $response['headers']['status-code']);

        $response = $this->signIn('alice', 'alicepass');

        $this->assertSame(201, $response['headers']['status-code']);
    }

    // Helpers

    /**
     * Point the project at the seeded OpenLDAP fixture and enable sign-in.
     * Enabling connects and binds, so a passing call also proves the fixture
     * is up. Passing no overrides resets every optional setting.
     */
    private function configureDirectory(array $overrides = []): void
    {
        $params = \array_merge([
            'hosts' => [self::HOST],
            'port' => 389,
            'encryption' => 'none',
            'tls' => true,
            'caCertificate' => '',
            'timeout' => 10,
            'baseDn' => self::BASE_DN,
            'bindDn' => self::BIND_DN,
            'bindPassword' => self::BIND_PASSWORD,
            'usernameAttribute' => 'uid',
            'userFilter' => '',
            'idAttribute' => 'entryUUID',
            'emailAttribute' => 'mail',
            'nameAttribute' => 'cn',
            'phoneAttribute' => '',
            'requiredGroupDn' => '',
            'enabled' => true,
        ], $overrides);

        $response = $this->updateLDAP($params);

        $this->assertSame(200, $response['headers']['status-code'], 'Could not configure the LDAP fixture: ' . \json_encode($response['body']));
        $this->assertSame($params['enabled'], $this->ldapEnabled($response['body']));
    }

    /**
     * Configure the default directory and sign Alice in, provisioning her account.
     *
     * @return array{userId: string, providerUid: string}
     */
    private function signInAlice(): array
    {
        $this->configureDirectory();

        $response = $this->signIn('alice', 'alicepass');

        $this->assertSame(201, $response['headers']['status-code'], 'Could not sign Alice in: ' . \json_encode($response['body']));

        return [
            'userId' => $response['body']['userId'],
            'providerUid' => $response['body']['providerUid'],
        ];
    }

    private function identitiesOf(string $userId): array
    {
        return $this->client->call(Client::METHOD_GET, '/users/identities', $this->serverHeaders(), [
            'queries' => [Query::equal('userId', [$userId])->toString()],
        ]);
    }

    private function updateLDAP(array $params): array
    {
        return $this->client->call(Client::METHOD_PATCH, '/project/ldap', $this->serverHeaders(), $params);
    }

    private function createLDAPTest(array $params = []): array
    {
        return $this->client->call(Client::METHOD_POST, '/project/ldap/tests', $this->serverHeaders(), $params);
    }

    private function updateAuthMethod(bool $enabled): array
    {
        return $this->client->call(Client::METHOD_PATCH, '/project/auth-methods/ldap', $this->serverHeaders(), [
            'enabled' => $enabled,
        ]);
    }

    private function ldapEnabled(array $project): bool
    {
        foreach ($project['authMethods'] ?? [] as $method) {
            if ($method['$id'] === 'ldap') {
                return $method['enabled'];
            }
        }

        $this->fail('The project does not list the ldap auth method.');
    }

    private function signIn(string $username, string $password, array $params = [], array $headers = []): array
    {
        return $this->client->call(Client::METHOD_POST, '/account/sessions/ldap', \array_merge($this->clientHeaders(), $headers), \array_merge([
            'username' => $username,
            'password' => $password,
        ], $params));
    }

    private function getDevice(string $cookie): array
    {
        return $this->client->call(Client::METHOD_GET, '/account/devices/current', \array_merge($this->clientHeaders(), [
            'cookie' => $cookie,
        ]));
    }

    /**
     * The CA that signed the fixture's certificate, generated by dev/openldap/certs.sh.
     */
    private function caCertificate(): string
    {
        $certificate = @\file_get_contents(self::CA_CERTIFICATE);

        if ($certificate === false || $certificate === '') {
            $this->markTestSkipped('The OpenLDAP fixture has no TLS certificate; run dev/openldap/certs.sh before starting it.');
        }

        return $certificate;
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
