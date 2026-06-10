<?php

namespace Tests\E2E\Services\Project;

use Tests\E2E\Client;

trait AuthMethodsBase
{
    /**
     * methodId => response field name exposed by the Project model.
     */
    protected static array $authMethods = [
        'email-password' => 'authEmailPassword',
        'magic-url'      => 'authUsersAuthMagicURL',
        'email-otp'      => 'authEmailOtp',
        'anonymous'      => 'authAnonymous',
        'invites'        => 'authInvites',
        'jwt'            => 'authJWT',
        'phone'          => 'authPhone',
    ];

    // Success flow

    public function testDisableAuthMethod(): void
    {
        foreach (self::$authMethods as $methodId => $responseKey) {
            $response = $this->updateAuthMethod($methodId, false);

            $this->assertSame(200, $response['headers']['status-code']);
            $this->assertNotEmpty($response['body']['$id']);
            $this->assertSame(false, $response['body'][$responseKey]);
        }

        // Cleanup
        foreach (self::$authMethods as $methodId => $responseKey) {
            $this->updateAuthMethod($methodId, true);
        }
    }

    public function testEnableAuthMethod(): void
    {
        // Disable first
        foreach (self::$authMethods as $methodId => $responseKey) {
            $this->updateAuthMethod($methodId, false);
        }

        // Re-enable
        foreach (self::$authMethods as $methodId => $responseKey) {
            $response = $this->updateAuthMethod($methodId, true);

            $this->assertSame(200, $response['headers']['status-code']);
            $this->assertNotEmpty($response['body']['$id']);
            $this->assertSame(true, $response['body'][$responseKey]);
        }
    }

    public function testDisableAuthMethodIdempotent(): void
    {
        $first = $this->updateAuthMethod('email-password', false);
        $this->assertSame(200, $first['headers']['status-code']);
        $this->assertSame(false, $first['body']['authEmailPassword']);

        $second = $this->updateAuthMethod('email-password', false);
        $this->assertSame(200, $second['headers']['status-code']);
        $this->assertSame(false, $second['body']['authEmailPassword']);

        // Cleanup
        $this->updateAuthMethod('email-password', true);
    }

    public function testEnableAuthMethodIdempotent(): void
    {
        $first = $this->updateAuthMethod('email-password', true);
        $this->assertSame(200, $first['headers']['status-code']);
        $this->assertSame(true, $first['body']['authEmailPassword']);

        $second = $this->updateAuthMethod('email-password', true);
        $this->assertSame(200, $second['headers']['status-code']);
        $this->assertSame(true, $second['body']['authEmailPassword']);
    }

    public function testDisableOneMethodDoesNotAffectOther(): void
    {
        // Ensure both start enabled
        $this->updateAuthMethod('email-password', true);
        $this->updateAuthMethod('magic-url', true);

        $response = $this->updateAuthMethod('email-password', false);
        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(false, $response['body']['authEmailPassword']);
        $this->assertSame(true, $response['body']['authUsersAuthMagicURL']);

        // Cleanup
        $this->updateAuthMethod('email-password', true);
    }

    public function testDisabledEmailPasswordBlocksSessionCreation(): void
    {
        $this->updateAuthMethod('email-password', false);

        // Unauthenticated account creation would normally be permitted; with the
        // method disabled we expect the shared auth filter to reject it.
        $response = $this->client->call(Client::METHOD_POST, '/account', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'userId' => 'unique()',
            'email' => 'disabled-method-' . \uniqid() . '@appwrite.io',
            'password' => 'password123',
        ]);

        $this->assertSame(501, $response['headers']['status-code']);
        $this->assertSame('user_auth_method_unsupported', $response['body']['type']);

        // Cleanup
        $this->updateAuthMethod('email-password', true);
    }

    public function testEnabledEmailPasswordAllowsSessionCreation(): void
    {
        $this->updateAuthMethod('email-password', false);
        $this->updateAuthMethod('email-password', true);

        $response = $this->client->call(Client::METHOD_POST, '/account', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'userId' => 'unique()',
            'email' => 'enabled-method-' . \uniqid() . '@appwrite.io',
            'password' => 'password123',
        ]);

        $this->assertNotSame(501, $response['headers']['status-code']);
        $this->assertNotSame('user_auth_method_unsupported', $response['body']['type'] ?? '');
    }

    public function testDisabledAnonymousBlocksSessionCreation(): void
    {
        $this->updateAuthMethod('anonymous', false);

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/anonymous', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        $this->assertSame(501, $response['headers']['status-code']);
        $this->assertSame('user_auth_method_unsupported', $response['body']['type']);

        // Cleanup
        $this->updateAuthMethod('anonymous', true);
    }

    public function testResponseModel(): void
    {
        $response = $this->updateAuthMethod('email-password', false);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertArrayHasKey('$id', $response['body']);
        $this->assertArrayHasKey('name', $response['body']);
        foreach (self::$authMethods as $methodId => $responseKey) {
            $this->assertArrayHasKey($responseKey, $response['body']);
        }

        // Cleanup
        $this->updateAuthMethod('email-password', true);
    }

    // Failure flow

    public function testUpdateAuthMethodWithoutAuthentication(): void
    {
        $response = $this->updateAuthMethod('email-password', false, false);

        $this->assertSame(401, $response['headers']['status-code']);
    }

    public function testUpdateAuthMethodInvalidMethodId(): void
    {
        $response = $this->updateAuthMethod('invalid-method', false);

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testUpdateAuthMethodEmptyMethodId(): void
    {
        $response = $this->updateAuthMethod('', false);

        $this->assertSame(404, $response['headers']['status-code']);
    }

    public function testUpdateAuthMethodMissingEnabled(): void
    {
        $headers = \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders());

        $response = $this->client->call(
            Client::METHOD_PATCH,
            '/project/auth-methods/email-password',
            $headers,
            []
        );

        $this->assertSame(400, $response['headers']['status-code']);
    }

    // Backwards compatibility

    public function testUpdateAuthMethodLegacyAliasPath(): void
    {
        $headers = \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-response-format' => '1.9.4',
        ], $this->getHeaders());

        $projectId = $this->getProject()['$id'];

        // Disable via the legacy `/v1/projects/:projectId/auth/:methodId` alias
        $response = $this->client->call(
            Client::METHOD_PATCH,
            '/projects/' . $projectId . '/auth/email-password',
            $headers,
            [
                'enabled' => false,
            ]
        );

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertSame(false, $response['body']['authEmailPassword']);

        // Re-enable via the legacy alias
        $response = $this->client->call(
            Client::METHOD_PATCH,
            '/projects/' . $projectId . '/auth/email-password',
            $headers,
            [
                'enabled' => true,
            ]
        );

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(true, $response['body']['authEmailPassword']);
    }

    public function testUpdateAuthMethodLegacyStatusParam(): void
    {
        // Old SDK passed `status` in the body. The V23 request filter (triggered
        // via `x-appwrite-response-format: 1.9.1`) must rename it to `enabled`.
        $headers = \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-response-format' => '1.9.1',
        ], $this->getHeaders());

        $projectId = $this->getProject()['$id'];

        $response = $this->client->call(
            Client::METHOD_PATCH,
            '/projects/' . $projectId . '/auth/email-password',
            $headers,
            [
                'status' => false,
            ]
        );

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(false, $response['body']['authEmailPassword']);

        $response = $this->client->call(
            Client::METHOD_PATCH,
            '/projects/' . $projectId . '/auth/email-password',
            $headers,
            [
                'status' => true,
            ]
        );

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(true, $response['body']['authEmailPassword']);
    }

    public function testUpdateAuthMethodLegacyMethodParam(): void
    {
        // Old SDK also had `method` as a path identifier; the V23 filter renames
        // a stray `method` body field to `methodId`. The URL path parameter of
        // the alias already binds to `:methodId`, so supplying `method` in the
        // body is tolerated.
        $headers = \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-response-format' => '1.9.1',
        ], $this->getHeaders());

        $projectId = $this->getProject()['$id'];

        $response = $this->client->call(
            Client::METHOD_PATCH,
            '/projects/' . $projectId . '/auth/email-password',
            $headers,
            [
                'method' => 'email-password',
                'status' => false,
            ]
        );

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(false, $response['body']['authEmailPassword']);

        // Cleanup
        $this->updateAuthMethod('email-password', true);
    }

    // Helpers

    protected function updateAuthMethod(string $methodId, bool $enabled, bool $authenticated = true): mixed
    {
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];

        if ($authenticated) {
            $headers = \array_merge($headers, $this->getHeaders(), [
                'x-appwrite-response-format' => '1.9.4',
            ]);
        }

        return $this->client->call(
            Client::METHOD_PATCH,
            '/project/auth-methods/' . $methodId,
            $headers,
            [
                'enabled' => $enabled,
            ]
        );
    }
}
