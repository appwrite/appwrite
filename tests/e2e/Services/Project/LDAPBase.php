<?php

namespace Tests\E2E\Services\Project;

use Tests\E2E\Client;

/**
 * `PATCH /v1/project/auth/ldap` without a live directory: configuration is
 * stored, merged and read back without connecting, and only enabling connects.
 * Sign-in flows against a real directory live in LDAPSessionIntegrationTest.
 */
trait LDAPBase
{
    // Success flow

    public function testUpdateConfig(): void
    {
        $response = $this->updateLDAP([
            'host' => 'directory.appwrite.test',
            'port' => 636,
            'encryption' => 'ssl',
            'baseDn' => 'dc=appwrite,dc=test',
            'bindDn' => 'cn=service,dc=appwrite,dc=test',
            'bindPassword' => 'servicepass',
            'userFilter' => '(uid={{username}})',
            'provisionGroupDn' => 'cn=staff,ou=groups,dc=appwrite,dc=test',
            'emailAttribute' => 'mail',
            'nameAttribute' => 'displayName',
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('ldap', $response['body']['$id']);
        $this->assertSame('directory.appwrite.test', $response['body']['host']);
        $this->assertSame(636, $response['body']['port']);
        $this->assertSame('ssl', $response['body']['encryption']);
        $this->assertSame('dc=appwrite,dc=test', $response['body']['baseDn']);
        $this->assertSame('cn=service,dc=appwrite,dc=test', $response['body']['bindDn']);
        $this->assertSame('(uid={{username}})', $response['body']['userFilter']);
        $this->assertSame('cn=staff,ou=groups,dc=appwrite,dc=test', $response['body']['provisionGroupDn']);
        $this->assertSame('mail', $response['body']['emailAttribute']);
        $this->assertSame('displayName', $response['body']['nameAttribute']);

        // Storing a configuration is not enabling it.
        $this->assertSame(false, $response['body']['enabled']);
    }

    public function testUpdateConfigMergesOverStored(): void
    {
        $this->updateLDAP([
            'host' => 'directory.appwrite.test',
            'port' => 389,
            'encryption' => 'tls',
            'baseDn' => 'dc=appwrite,dc=test',
        ]);

        // Sending one field must not blank the others.
        $response = $this->updateLDAP([
            'emailAttribute' => 'userPrincipalName',
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('userPrincipalName', $response['body']['emailAttribute']);
        $this->assertSame('directory.appwrite.test', $response['body']['host']);
        $this->assertSame(389, $response['body']['port']);
        $this->assertSame('tls', $response['body']['encryption']);
        $this->assertSame('dc=appwrite,dc=test', $response['body']['baseDn']);
    }

    public function testBindPasswordIsWriteOnly(): void
    {
        $response = $this->updateLDAP([
            'bindPassword' => 'topsecret',
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertArrayNotHasKey('bindPassword', $response['body']);

        // Not in any later read either.
        $response = $this->updateLDAP([]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertArrayNotHasKey('bindPassword', $response['body']);
    }

    public function testResponseModel(): void
    {
        $response = $this->updateLDAP([]);

        $this->assertSame(200, $response['headers']['status-code']);
        foreach ([
            '$id',
            'enabled',
            'host',
            'port',
            'encryption',
            'baseDn',
            'bindDn',
            'userFilter',
            'provisionGroupDn',
            'emailAttribute',
            'nameAttribute',
        ] as $key) {
            $this->assertArrayHasKey($key, $response['body']);
        }
    }

    public function testDisableNeverConnects(): void
    {
        // Disabling with an unreachable (or empty) configuration must succeed:
        // only enabling proves the directory works.
        $response = $this->updateLDAP([
            'host' => 'unreachable.appwrite.test',
            'enabled' => false,
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(false, $response['body']['enabled']);
    }

    public function testGenericToggleControlsSameFlag(): void
    {
        // The generic auth-method endpoint and the LDAP endpoint flip the same
        // flag, so each must observe the other's writes.
        $response = $this->client->call(Client::METHOD_PATCH, '/project/auth-methods/ldap', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'enabled' => true,
        ]);

        $this->assertSame(200, $response['headers']['status-code']);

        $response = $this->updateLDAP([]);
        $this->assertSame(true, $response['body']['enabled']);

        $response = $this->client->call(Client::METHOD_PATCH, '/project/auth-methods/ldap', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'enabled' => false,
        ]);

        $this->assertSame(200, $response['headers']['status-code']);

        $response = $this->updateLDAP([]);
        $this->assertSame(false, $response['body']['enabled']);
    }

    // Failure flow

    public function testEnableRequiresConfiguration(): void
    {
        $response = $this->updateLDAP([
            'host' => '',
            'enabled' => true,
        ]);

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $response['body']['type']);
        $this->assertStringContainsString('Could not enable LDAP', $response['body']['message']);
    }

    public function testEnableWithUnreachableDirectoryFails(): void
    {
        // Port 1 on loopback refuses immediately, so this asserts the
        // connection is really attempted without waiting out a timeout.
        $response = $this->updateLDAP([
            'host' => '127.0.0.1',
            'port' => 1,
            'encryption' => 'none',
            'baseDn' => 'dc=appwrite,dc=test',
            'userFilter' => '(uid={{username}})',
            'emailAttribute' => 'mail',
            'enabled' => true,
        ]);

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $response['body']['type']);
        $this->assertStringContainsString('Could not enable LDAP', $response['body']['message']);

        // A failed enable must not leave the method on.
        $response = $this->updateLDAP([]);
        $this->assertSame(false, $response['body']['enabled']);
    }

    public function testInvalidEncryptionIsRejected(): void
    {
        $response = $this->updateLDAP([
            'encryption' => 'rot13',
        ]);

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testUpdateWithoutAuthentication(): void
    {
        $response = $this->updateLDAP([
            'host' => 'directory.appwrite.test',
        ], false);

        $this->assertSame(401, $response['headers']['status-code']);
    }

    // Helpers

    protected function updateLDAP(array $params, bool $authenticated = true): mixed
    {
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];

        if ($authenticated) {
            $headers = \array_merge($headers, $this->getHeaders());
        }

        return $this->client->call(
            Client::METHOD_PATCH,
            '/project/auth/ldap',
            $headers,
            $params
        );
    }
}
