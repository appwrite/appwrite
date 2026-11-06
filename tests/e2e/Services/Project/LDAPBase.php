<?php

namespace Tests\E2E\Services\Project;

use PHPUnit\Framework\Attributes\Before;
use Tests\E2E\Client;

/**
 * `PATCH /v1/project/ldap` and `POST /v1/project/ldap/tests` without a live
 * directory: configuration is stored, merged and read back through the project
 * without connecting, and only enabling or testing connects. Sign-in flows
 * against a real directory live in LDAPSessionIntegrationTest.
 */
trait LDAPBase
{
    // The ProjectCustom trait reuses the same project across tests in a class
    // and the PATCH endpoint is additive, so state leaks across tests. Reset to
    // a known, disabled configuration with no stored bind password before each
    // test: a stored password refuses every later move of the host.
    #[Before(priority: -1)]
    protected function resetProjectLDAP(): void
    {
        $this->updateLDAP([
            'hosts' => ['directory.appwrite.test'],
            'port' => 636,
            'encryption' => 'ldaps',
            'tls' => true,
            'caCertificate' => '',
            'timeout' => 10,
            'baseDn' => 'dc=appwrite,dc=test',
            'bindDn' => '',
            'bindPassword' => '',
            'usernameAttribute' => 'uid',
            'userFilter' => '',
            'idAttribute' => 'entryUUID',
            'emailAttribute' => 'mail',
            'nameAttribute' => 'cn',
            'phoneAttribute' => '',
            'requiredGroupDn' => '',
            'enabled' => false,
        ]);
    }

    // Update LDAP tests

    public function testUpdateLDAP(): void
    {
        $response = $this->updateLDAP([
            'hosts' => ['directory.appwrite.test', 'directory2.appwrite.test'],
            'port' => 3389,
            'encryption' => 'starttls',
            'tls' => false,
            'caCertificate' => "-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----",
            'timeout' => 5,
            'baseDn' => 'ou=people,dc=appwrite,dc=test',
            'bindDn' => 'cn=service,dc=appwrite,dc=test',
            'bindPassword' => 'servicepass',
            'usernameAttribute' => 'sAMAccountName',
            'userFilter' => '(objectClass=person)',
            'idAttribute' => 'objectGUID',
            'emailAttribute' => 'userPrincipalName',
            'nameAttribute' => 'displayName',
            'phoneAttribute' => 'mobile',
            'requiredGroupDn' => 'cn=staff,ou=groups,dc=appwrite,dc=test',
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame($this->getProject()['$id'], $response['body']['$id']);
        $this->assertSame(['directory.appwrite.test', 'directory2.appwrite.test'], $response['body']['ldapHosts']);
        $this->assertSame(3389, $response['body']['ldapPort']);
        $this->assertSame('starttls', $response['body']['ldapEncryption']);
        $this->assertSame(false, $response['body']['ldapTls']);
        $this->assertSame("-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----", $response['body']['ldapCaCertificate']);
        $this->assertSame(5, $response['body']['ldapTimeout']);
        $this->assertSame('ou=people,dc=appwrite,dc=test', $response['body']['ldapBaseDn']);
        $this->assertSame('cn=service,dc=appwrite,dc=test', $response['body']['ldapBindDn']);
        $this->assertSame('sAMAccountName', $response['body']['ldapUsernameAttribute']);
        $this->assertSame('(objectClass=person)', $response['body']['ldapUserFilter']);
        $this->assertSame('objectGUID', $response['body']['ldapIdAttribute']);
        $this->assertSame('userPrincipalName', $response['body']['ldapEmailAttribute']);
        $this->assertSame('displayName', $response['body']['ldapNameAttribute']);
        $this->assertSame('mobile', $response['body']['ldapPhoneAttribute']);
        $this->assertSame('cn=staff,ou=groups,dc=appwrite,dc=test', $response['body']['ldapRequiredGroupDn']);

        // Storing a configuration is not enabling it.
        $this->assertSame(false, $this->ldapEnabled($response['body']));

        // The same values are read back from the project.
        $project = $this->getProjectDocument();
        $this->assertSame(['directory.appwrite.test', 'directory2.appwrite.test'], $project['ldapHosts']);
        $this->assertSame('sAMAccountName', $project['ldapUsernameAttribute']);
        $this->assertSame(false, $this->ldapEnabled($project));
    }

    public function testUpdateLDAPDefaults(): void
    {
        // A project that has never stored a port or an attribute mapping reports
        // the values a sign-in would use, and the port follows the encryption.
        $project = $this->getProject(fresh: true);

        $response = $this->updateLDAP([
            'hosts' => ['directory.appwrite.test'],
            'baseDn' => 'dc=appwrite,dc=test',
        ], project: $project);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(636, $response['body']['ldapPort']);
        $this->assertSame('ldaps', $response['body']['ldapEncryption']);
        $this->assertSame(true, $response['body']['ldapTls']);
        $this->assertSame('', $response['body']['ldapCaCertificate']);
        $this->assertSame(10, $response['body']['ldapTimeout']);
        $this->assertSame('', $response['body']['ldapBindDn']);
        $this->assertSame('uid', $response['body']['ldapUsernameAttribute']);
        $this->assertSame('', $response['body']['ldapUserFilter']);
        $this->assertSame('entryUUID', $response['body']['ldapIdAttribute']);
        $this->assertSame('mail', $response['body']['ldapEmailAttribute']);
        $this->assertSame('cn', $response['body']['ldapNameAttribute']);
        $this->assertSame('', $response['body']['ldapPhoneAttribute']);
        $this->assertSame('', $response['body']['ldapRequiredGroupDn']);
        $this->assertSame(false, $this->ldapEnabled($response['body']));

        $response = $this->updateLDAP([
            'encryption' => 'starttls',
        ], project: $project);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(389, $response['body']['ldapPort']);

        // A port chosen explicitly no longer follows the encryption.
        $response = $this->updateLDAP([
            'port' => 3389,
            'encryption' => 'ldaps',
        ], project: $project);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(3389, $response['body']['ldapPort']);
    }

    public function testUpdateLDAPMergesOverStored(): void
    {
        // Sending one field must not blank the others.
        $response = $this->updateLDAP([
            'emailAttribute' => 'userPrincipalName',
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('userPrincipalName', $response['body']['ldapEmailAttribute']);
        $this->assertSame(['directory.appwrite.test'], $response['body']['ldapHosts']);
        $this->assertSame(636, $response['body']['ldapPort']);
        $this->assertSame('ldaps', $response['body']['ldapEncryption']);
        $this->assertSame('dc=appwrite,dc=test', $response['body']['ldapBaseDn']);
        $this->assertSame('cn', $response['body']['ldapNameAttribute']);
    }

    public function testUpdateLDAPClearsOptionalValues(): void
    {
        $response = $this->updateLDAP([
            'userFilter' => '(objectClass=person)',
            'phoneAttribute' => 'mobile',
            'requiredGroupDn' => 'cn=staff,ou=groups,dc=appwrite,dc=test',
            'caCertificate' => "-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----",
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('mobile', $response['body']['ldapPhoneAttribute']);

        // An empty string clears, where null would keep.
        $response = $this->updateLDAP([
            'userFilter' => '',
            'phoneAttribute' => '',
            'requiredGroupDn' => '',
            'caCertificate' => '',
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('', $response['body']['ldapUserFilter']);
        $this->assertSame('', $response['body']['ldapPhoneAttribute']);
        $this->assertSame('', $response['body']['ldapRequiredGroupDn']);
        $this->assertSame('', $response['body']['ldapCaCertificate']);
    }

    public function testUpdateLDAPBindPasswordIsWriteOnly(): void
    {
        $response = $this->updateLDAP([
            'bindDn' => 'cn=service,dc=appwrite,dc=test',
            'bindPassword' => 'topsecret',
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertArrayHasKey('ldapBindPassword', $response['body']);
        $this->assertSame('', $response['body']['ldapBindPassword']);

        // Not in any later read either.
        $project = $this->getProjectDocument();
        $this->assertSame('', $project['ldapBindPassword']);
    }

    public function testUpdateLDAPMovingDirectoryRequiresBindPasswordAgain(): void
    {
        // A stored password belongs to the directory it was entered for. Pointing
        // the connection somewhere else without re-supplying it would hand the
        // service credential to whatever answers at the new address.
        $response = $this->updateLDAP([
            'bindDn' => 'cn=service,dc=appwrite,dc=test',
            'bindPassword' => 'servicepass',
        ]);

        $this->assertSame(200, $response['headers']['status-code']);

        foreach ([
            ['hosts' => ['attacker.example.com']],
            ['port' => 3389],
            ['encryption' => 'none'],
            ['bindDn' => 'cn=other,dc=appwrite,dc=test'],
        ] as $change) {
            $response = $this->updateLDAP($change);

            $this->assertSame(400, $response['headers']['status-code'], 'Expected refusal for: ' . \json_encode($change));
            $this->assertSame('general_argument_invalid', $response['body']['type']);
        }

        // The same destination spelled out again is not a move.
        $response = $this->updateLDAP([
            'hosts' => ['directory.appwrite.test'],
            'port' => 636,
            'encryption' => 'ldaps',
        ]);

        $this->assertSame(200, $response['headers']['status-code']);

        // Changes that do not move the credential stay mergeable.
        $response = $this->updateLDAP([
            'nameAttribute' => 'displayName',
            'requiredGroupDn' => 'cn=staff,ou=groups,dc=appwrite,dc=test',
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('displayName', $response['body']['ldapNameAttribute']);

        // Supplying the password again is how the move is authorised.
        $response = $this->updateLDAP([
            'hosts' => ['directory2.appwrite.test'],
            'bindPassword' => 'servicepass',
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(['directory2.appwrite.test'], $response['body']['ldapHosts']);
    }

    public function testUpdateLDAPResponseModel(): void
    {
        $response = $this->updateLDAP([]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertArrayHasKey('$id', $response['body']);
        $this->assertArrayHasKey('name', $response['body']);
        $this->assertArrayHasKey('authMethods', $response['body']);

        foreach ([
            'ldapHosts',
            'ldapPort',
            'ldapEncryption',
            'ldapTls',
            'ldapCaCertificate',
            'ldapTimeout',
            'ldapBaseDn',
            'ldapBindDn',
            'ldapBindPassword',
            'ldapUsernameAttribute',
            'ldapUserFilter',
            'ldapIdAttribute',
            'ldapEmailAttribute',
            'ldapNameAttribute',
            'ldapPhoneAttribute',
            'ldapRequiredGroupDn',
        ] as $key) {
            $this->assertArrayHasKey($key, $response['body']);
        }

        $this->assertIsArray($response['body']['ldapHosts']);
        $this->assertIsInt($response['body']['ldapPort']);
        $this->assertIsBool($response['body']['ldapTls']);
        $this->assertIsInt($response['body']['ldapTimeout']);
    }

    public function testUpdateLDAPStatusDisableNeverConnects(): void
    {
        // Disabling with an unreachable configuration must succeed: only
        // enabling proves the directory works.
        $response = $this->updateLDAP([
            'hosts' => ['unreachable.appwrite.test'],
            'enabled' => false,
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(false, $this->ldapEnabled($response['body']));
    }

    public function testUpdateLDAPStatusSharesAuthMethodFlag(): void
    {
        // The generic auth-method endpoint and the LDAP endpoint flip the same
        // flag, so each must observe the other's writes. The generic endpoint
        // does not connect, so an unreachable directory can be switched on here.
        $response = $this->updateAuthMethod(true);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(true, $this->ldapEnabled($response['body']));
        $this->assertSame(true, $this->ldapEnabled($this->getProjectDocument()));

        $response = $this->updateLDAP(['enabled' => false]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(false, $this->ldapEnabled($response['body']));

        $response = $this->updateAuthMethod(false);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(false, $this->ldapEnabled($this->getProjectDocument()));
    }

    public function testUpdateLDAPEnabledConfigurationIsCheckedOnChange(): void
    {
        // Once enabled, a change to the configuration is checked the way enabling
        // is: a project cannot be left enabled with a directory nobody can reach.
        $response = $this->updateAuthMethod(true);
        $this->assertSame(200, $response['headers']['status-code']);

        $response = $this->updateLDAP([
            'nameAttribute' => 'displayName',
        ]);

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('project_ldap_config_invalid', $response['body']['type']);

        // Nothing was stored by the refused call.
        $this->assertSame('cn', $this->getProjectDocument()['ldapNameAttribute']);

        // Disabling in the same call stores the change without connecting.
        $response = $this->updateLDAP([
            'nameAttribute' => 'displayName',
            'enabled' => false,
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('displayName', $response['body']['ldapNameAttribute']);
        $this->assertSame(false, $this->ldapEnabled($response['body']));
    }

    public function testUpdateLDAPStatusWithoutAuthentication(): void
    {
        $response = $this->updateLDAP(['enabled' => true], authenticated: false);

        $this->assertSame(401, $response['headers']['status-code']);
    }

    public function testUpdateLDAPStatusEnableRequiresHosts(): void
    {
        $response = $this->updateLDAP([
            'hosts' => [],
            'enabled' => true,
        ]);

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('project_ldap_config_invalid', $response['body']['type']);
        $this->assertStringContainsString('hosts', $response['body']['message']);
        $this->assertSame(false, $this->ldapEnabled($this->getProjectDocument()));
    }

    public function testUpdateLDAPStatusEnableRequiresBaseDn(): void
    {
        $response = $this->updateLDAP([
            'baseDn' => '',
            'enabled' => true,
        ]);

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('project_ldap_config_invalid', $response['body']['type']);
        $this->assertStringContainsString('baseDn', $response['body']['message']);
    }

    public function testUpdateLDAPStatusEnableWithUnreachableDirectoryFails(): void
    {
        // Port 1 on loopback refuses immediately, so this asserts the
        // connection is really attempted without waiting out a timeout.
        $response = $this->updateLDAP([
            'hosts' => ['127.0.0.1'],
            'port' => 1,
            'encryption' => 'none',
            'enabled' => true,
        ]);

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('project_ldap_config_invalid', $response['body']['type']);
        $this->assertStringContainsString('127.0.0.1:1', $response['body']['message']);

        // A failed enable must not leave the method on, nor store the change.
        $project = $this->getProjectDocument();
        $this->assertSame(false, $this->ldapEnabled($project));
        $this->assertSame(['directory.appwrite.test'], $project['ldapHosts']);
    }

    public function testUpdateLDAPAuthMethodEnableRequiresConfiguration(): void
    {
        $response = $this->updateLDAP([
            'hosts' => [],
        ]);

        $this->assertSame(200, $response['headers']['status-code']);

        // The generic toggle does not connect, but it will not switch on a
        // method that has no directory to sign anyone in against.
        $response = $this->updateAuthMethod(true);

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('project_ldap_config_invalid', $response['body']['type']);
        $this->assertSame(false, $this->ldapEnabled($this->getProjectDocument()));
    }

    public function testUpdateLDAPRejectsPrivateHost(): void
    {
        // A host inside the network is refused at write time: the service
        // credential must not be sent to, and the error message must not
        // describe, anything Appwrite can reach but the caller cannot.
        foreach (['10.0.0.1', '192.168.1.10', '169.254.169.254'] as $host) {
            $response = $this->updateLDAP([
                'hosts' => [$host],
            ]);

            $this->assertSame(400, $response['headers']['status-code'], 'Expected refusal for host: ' . $host);
            $this->assertSame('general_argument_invalid', $response['body']['type']);
        }

        $this->assertSame(['directory.appwrite.test'], $this->getProjectDocument()['ldapHosts']);
    }

    public function testUpdateLDAPInvalidValues(): void
    {
        foreach ([
            ['hosts' => ['http://directory.appwrite.test']],
            ['hosts' => ['directory.appwrite.test:636']],
            ['hosts' => \array_fill(0, 11, 'directory.appwrite.test')],
            ['port' => 0],
            ['port' => 65536],
            ['encryption' => 'ssl'],
            ['encryption' => 'tls'],
            ['timeout' => 0],
            ['timeout' => 31],
            ['usernameAttribute' => ''],
            ['idAttribute' => ''],
            ['emailAttribute' => ''],
            ['nameAttribute' => ''],
            ['tls' => 'yes'],
        ] as $params) {
            $response = $this->updateLDAP($params);

            $this->assertSame(400, $response['headers']['status-code'], 'Expected refusal for: ' . \json_encode($params));
            $this->assertSame('general_argument_invalid', $response['body']['type']);
        }
    }

    // Create LDAP test tests

    public function testCreateLDAPTestRequiresConfiguration(): void
    {
        $response = $this->updateLDAP([
            'hosts' => [],
        ]);

        $this->assertSame(200, $response['headers']['status-code']);

        $response = $this->createLDAPTest();

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('project_ldap_config_invalid', $response['body']['type']);
        $this->assertStringContainsString('hosts', $response['body']['message']);
    }

    public function testCreateLDAPTestUnreachableDirectory(): void
    {
        // Testing works on a disabled configuration: it is how an admin checks
        // the settings before switching sign-in on.
        $response = $this->updateLDAP([
            'hosts' => ['127.0.0.1'],
            'port' => 1,
            'encryption' => 'none',
        ]);

        $this->assertSame(200, $response['headers']['status-code']);

        $response = $this->createLDAPTest();

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('project_ldap_config_invalid', $response['body']['type']);
        $this->assertStringContainsString('127.0.0.1:1', $response['body']['message']);
    }

    public function testCreateLDAPTestWithoutAuthentication(): void
    {
        $response = $this->createLDAPTest(authenticated: false);

        $this->assertSame(401, $response['headers']['status-code']);
    }

    // Helpers

    protected function updateLDAP(array $params, bool $authenticated = true, ?array $project = null): array
    {
        return $this->client->call(Client::METHOD_PATCH, '/project/ldap', $this->projectHeaders($authenticated, $project), $params);
    }

    protected function createLDAPTest(array $params = [], bool $authenticated = true): array
    {
        return $this->client->call(Client::METHOD_POST, '/project/ldap/tests', $this->projectHeaders($authenticated), $params);
    }

    protected function updateAuthMethod(bool $enabled): array
    {
        return $this->client->call(Client::METHOD_PATCH, '/project/auth-methods/ldap', $this->projectHeaders(), [
            'enabled' => $enabled,
        ]);
    }

    protected function getProjectDocument(): array
    {
        $response = $this->client->call(Client::METHOD_GET, '/project', $this->projectHeaders());

        $this->assertSame(200, $response['headers']['status-code']);

        return $response['body'];
    }

    /**
     * Headers for the project API: the console session or the API key of the
     * project under test, which is the shared one unless another is given.
     */
    protected function projectHeaders(bool $authenticated = true, ?array $project = null): array
    {
        $project ??= $this->getProject();

        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $project['$id'],
        ];

        if (!$authenticated) {
            return $headers;
        }

        $auth = $this->getHeaders();
        if (isset($auth['x-appwrite-key'])) {
            $auth['x-appwrite-key'] = $project['apiKey'];
        }

        return \array_merge($headers, $auth);
    }

    /**
     * Whether LDAP sign-in is enabled, as the project reports it among its auth methods.
     */
    protected function ldapEnabled(array $project): bool
    {
        foreach ($project['authMethods'] ?? [] as $method) {
            if ($method['$id'] === 'ldap') {
                return $method['enabled'];
            }
        }

        $this->fail('The project does not list the ldap auth method: ' . \json_encode($project['authMethods'] ?? null));
    }
}
