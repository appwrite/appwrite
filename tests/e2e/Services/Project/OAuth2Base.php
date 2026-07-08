<?php

namespace Tests\E2E\Services\Project;

use PHPUnit\Framework\Attributes\Before;
use Tests\E2E\Client;
use Utopia\Database\Query;

trait OAuth2Base
{
    /**
     * Reset only providers this compact smoke suite mutates. Most provider
     * matrix and response-shape coverage now lives in unit tests.
     */
    #[Before(priority: -1)]
    protected function resetProjectOAuth2(): void
    {
        $providers = [
            'amazon' => ['clientId' => '', 'clientSecret' => '', 'enabled' => false],
            'github' => ['clientId' => '', 'clientSecret' => '', 'enabled' => false],
            'apple' => ['serviceId' => '', 'keyId' => '', 'teamId' => '', 'p8File' => '', 'enabled' => false],
            'oidc' => ['clientId' => '', 'clientSecret' => '', 'wellKnownURL' => '', 'authorizationURL' => '', 'tokenURL' => '', 'userInfoURL' => '', 'enabled' => false],
            'okta' => ['clientId' => '', 'clientSecret' => '', 'domain' => '', 'authorizationServerId' => '', 'enabled' => false],
            'google' => ['clientId' => '', 'clientSecret' => '', 'prompt' => ['consent'], 'enabled' => false],
            'dropbox' => ['appKey' => '', 'appSecret' => '', 'enabled' => false],
        ];

        foreach ($providers as $provider => $payload) {
            $response = $this->updateOAuth2($provider, $payload);
            $this->assertSame(
                200,
                $response['headers']['status-code'],
                "OAuth2 reset failed for {$provider}. Body: " . \json_encode($response['body'] ?? null),
            );
        }
    }

    public function testListOAuth2Providers(): void
    {
        $response = $this->listOAuth2Providers();

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertArrayHasKey('total', $response['body']);
        $this->assertArrayHasKey('providers', $response['body']);
        $this->assertGreaterThan(0, $response['body']['total']);
        $this->assertSame($response['body']['total'], \count($response['body']['providers']));

        $ids = \array_column($response['body']['providers'], '$id');
        $this->assertContains('github', $ids);
        $this->assertContains('apple', $ids);
        $this->assertContains('oidc', $ids);
    }

    public function testListOAuth2ProvidersWithoutAuthentication(): void
    {
        $response = $this->listOAuth2Providers(authenticated: false);

        $this->assertSame(401, $response['headers']['status-code']);
    }

    public function testListOAuth2ProvidersTotalFalse(): void
    {
        $response = $this->listOAuth2Providers(total: false);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(0, $response['body']['total']);
        $this->assertGreaterThan(0, \count($response['body']['providers']));
    }

    public function testListOAuth2ProvidersWithLimitAndOffset(): void
    {
        $listAll = $this->listOAuth2Providers();
        $this->assertSame(200, $listAll['headers']['status-code']);

        $limited = $this->listOAuth2Providers([
            Query::limit(1)->toString(),
        ]);
        $this->assertSame(200, $limited['headers']['status-code']);
        $this->assertCount(1, $limited['body']['providers']);
        $this->assertSame($listAll['body']['total'], $limited['body']['total']);

        $offset = $this->listOAuth2Providers([
            Query::offset(1)->toString(),
        ]);
        $this->assertSame(200, $offset['headers']['status-code']);
        $this->assertCount(\count($listAll['body']['providers']) - 1, $offset['body']['providers']);
        $this->assertSame($listAll['body']['total'], $offset['body']['total']);
    }

    public function testGetOAuth2Provider(): void
    {
        $response = $this->getOAuth2Provider('github');

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('github', $response['body']['$id']);
        $this->assertArrayHasKey('enabled', $response['body']);
        $this->assertArrayHasKey('clientId', $response['body']);
        $this->assertSame('', $response['body']['clientSecret']);
    }

    public function testGetOAuth2ProviderWithAlias(): void
    {
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];
        $headers = \array_merge($headers, $this->getHeaders());

        $response = $this->client->call(
            Client::METHOD_GET,
            '/project/oauth2/github?provider=github',
            $headers,
        );

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('github', $response['body']['$id']);
    }

    public function testGetOAuth2ProviderUnsupported(): void
    {
        $response = $this->getOAuth2Provider('not-a-real-provider');

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $response['body']['type']);
    }

    public function testGetOAuth2ProviderRegisteredInConfigButNoUpdateClass(): void
    {
        $response = $this->getOAuth2Provider('mock');

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('project_provider_unsupported', $response['body']['type']);
    }

    public function testGetOAuth2ProviderWithoutAuthentication(): void
    {
        $response = $this->getOAuth2Provider('github', authenticated: false);

        $this->assertSame(401, $response['headers']['status-code']);
    }

    public function testUpdateOAuth2PlainProviderRoundTrip(): void
    {
        $update = $this->updateOAuth2('amazon', [
            'clientId' => 'amzn1.application-oa2-client.smoke',
            'clientSecret' => 'smoke-secret',
            'enabled' => true,
        ]);

        $this->assertSame(200, $update['headers']['status-code']);
        $this->assertSame('amazon', $update['body']['$id']);
        $this->assertSame('amzn1.application-oa2-client.smoke', $update['body']['clientId']);
        $this->assertTrue($update['body']['enabled']);

        $get = $this->getOAuth2Provider('amazon');
        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertSame('amzn1.application-oa2-client.smoke', $get['body']['clientId']);
        $this->assertSame('', $get['body']['clientSecret']);
        $this->assertTrue($get['body']['enabled']);
    }

    public function testUpdateOAuth2WithoutAuthentication(): void
    {
        $response = $this->updateOAuth2('amazon', [
            'clientId' => 'no-auth',
            'clientSecret' => 'no-auth',
            'enabled' => false,
        ], authenticated: false);

        $this->assertSame(401, $response['headers']['status-code']);
    }

    public function testUpdateOAuth2UnknownProvider(): void
    {
        $response = $this->updateOAuth2('not-a-real-provider', [
            'clientId' => 'whatever',
            'clientSecret' => 'whatever',
            'enabled' => false,
        ]);

        $this->assertSame(404, $response['headers']['status-code']);
    }

    public function testUpdateOAuth2InvalidEnabled(): void
    {
        $response = $this->updateOAuth2('amazon', [
            'enabled' => 'not-a-boolean',
        ]);

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $response['body']['type']);
    }

    public function testUpdateOAuth2PlainEnableRequiresCredentials(): void
    {
        $response = $this->updateOAuth2('amazon', [
            'enabled' => true,
        ]);

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $response['body']['type']);
    }

    public function testUpdateOAuth2GitHubInvalidCredentialsRejected(): void
    {
        $response = $this->updateOAuth2('github', [
            'clientId' => 'fake-client-id-' . \uniqid(),
            'clientSecret' => 'fake-client-secret',
            'enabled' => true,
        ]);

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $response['body']['type']);
    }

    public function testUpdateOAuth2GitHubInvalidCredentialsSilentWhenNotEnabling(): void
    {
        $response = $this->updateOAuth2('github', [
            'clientId' => 'still-fake-' . \uniqid(),
            'clientSecret' => 'still-fake-secret',
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertFalse($response['body']['enabled']);
    }

    public function testUpdateOAuth2AppleRoundTrip(): void
    {
        $update = $this->updateOAuth2('apple', [
            'serviceId' => 'ip.appwrite.app.web',
            'keyId' => 'P4000000N8',
            'teamId' => 'D4000000R6',
            'p8File' => '-----BEGIN PRIVATE KEY-----TEST-----END PRIVATE KEY-----',
            'enabled' => true,
        ]);

        $this->assertSame(200, $update['headers']['status-code']);
        $this->assertSame('apple', $update['body']['$id']);
        $this->assertSame('ip.appwrite.app.web', $update['body']['serviceId']);
        $this->assertSame('P4000000N8', $update['body']['keyId']);
        $this->assertSame('D4000000R6', $update['body']['teamId']);
        $this->assertSame('', $update['body']['p8File']);
        $this->assertTrue($update['body']['enabled']);

        $get = $this->getOAuth2Provider('apple');
        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertSame('ip.appwrite.app.web', $get['body']['serviceId']);
        $this->assertSame('', $get['body']['p8File']);
    }

    public function testUpdateOAuth2OidcRoundTrip(): void
    {
        $update = $this->updateOAuth2('oidc', [
            'clientId' => 'oidc-client',
            'clientSecret' => 'oidc-secret',
            'wellKnownURL' => 'https://idp.example/.well-known/openid-configuration',
            'enabled' => true,
        ]);

        $this->assertSame(200, $update['headers']['status-code']);
        $this->assertSame('oidc', $update['body']['$id']);
        $this->assertSame('oidc-client', $update['body']['clientId']);
        $this->assertSame('', $update['body']['clientSecret']);
        $this->assertSame('https://idp.example/.well-known/openid-configuration', $update['body']['wellKnownURL']);
        $this->assertTrue($update['body']['enabled']);
    }

    public function testUpdateOAuth2OidcRejectsIncompleteDiscoveryConfig(): void
    {
        $response = $this->updateOAuth2('oidc', [
            'clientId' => 'oidc-client',
            'clientSecret' => 'oidc-secret',
            'authorizationURL' => 'https://idp.example/oauth2/authorize',
            'userInfoURL' => 'https://idp.example/oauth2/userinfo',
            'enabled' => true,
        ]);

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $response['body']['type']);
    }

    public function testUpdateOAuth2OktaRoundTrip(): void
    {
        $update = $this->updateOAuth2('okta', [
            'clientId' => '0oa00000000000000698',
            'clientSecret' => 'okta-secret',
            'domain' => 'trial-6400025.okta.com',
            'authorizationServerId' => 'aus000000000000000h7z',
            'enabled' => true,
        ]);

        $this->assertSame(200, $update['headers']['status-code']);
        $this->assertSame('okta', $update['body']['$id']);
        $this->assertSame('0oa00000000000000698', $update['body']['clientId']);
        $this->assertSame('trial-6400025.okta.com', $update['body']['domain']);
        $this->assertSame('aus000000000000000h7z', $update['body']['authorizationServerId']);
        $this->assertSame('', $update['body']['clientSecret']);
        $this->assertTrue($update['body']['enabled']);
    }

    public function testUpdateOAuth2GoogleRoundTrip(): void
    {
        $update = $this->updateOAuth2('google', [
            'clientId' => 'google-client',
            'clientSecret' => 'google-secret',
            'prompt' => ['select_account'],
            'enabled' => true,
        ]);

        $this->assertSame(200, $update['headers']['status-code']);
        $this->assertSame('google', $update['body']['$id']);
        $this->assertSame('google-client', $update['body']['clientId']);
        $this->assertSame(['select_account'], $update['body']['prompt']);
        $this->assertSame('', $update['body']['clientSecret']);
        $this->assertTrue($update['body']['enabled']);
    }

    public function testUpdateOAuth2DropboxCustomFieldRoundTrip(): void
    {
        $update = $this->updateOAuth2('dropbox', [
            'appKey' => 'dropbox-app-key',
            'appSecret' => 'dropbox-app-secret',
            'enabled' => false,
        ]);

        $this->assertSame(200, $update['headers']['status-code']);
        $this->assertSame('dropbox', $update['body']['$id']);
        $this->assertSame('dropbox-app-key', $update['body']['appKey']);
        $this->assertSame('', $update['body']['appSecret']);
        $this->assertArrayNotHasKey('clientId', $update['body']);
        $this->assertArrayNotHasKey('clientSecret', $update['body']);

        $get = $this->getOAuth2Provider('dropbox');
        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertSame('dropbox-app-key', $get['body']['appKey']);
        $this->assertSame('', $get['body']['appSecret']);
    }

    /**
     * @param array<string, mixed> $params
     */
    protected function updateOAuth2(string $provider, array $params, bool $authenticated = true): mixed
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
            '/project/oauth2/' . $provider,
            $headers,
            $params,
        );
    }

    protected function getOAuth2Provider(string $providerId, bool $authenticated = true): mixed
    {
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];

        if ($authenticated) {
            $headers = \array_merge($headers, $this->getHeaders());
        }

        return $this->client->call(
            Client::METHOD_GET,
            '/project/oauth2/' . $providerId,
            $headers,
        );
    }

    protected function listOAuth2Providers(?array $queries = null, ?bool $total = null, bool $authenticated = true): mixed
    {
        $params = [];

        if ($queries !== null) {
            $params['queries'] = $queries;
        }

        if ($total !== null) {
            $params['total'] = $total;
        }

        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];

        if ($authenticated) {
            $headers = \array_merge($headers, $this->getHeaders());
        }

        return $this->client->call(
            Client::METHOD_GET,
            '/project/oauth2',
            $headers,
            $params,
        );
    }

    public function testUpdateOAuth2OidcPromptAndMaxAge(): void
    {
        $response = $this->updateOAuth2('oidc', [
            'clientId' => 'oidc-prompt-client',
            'clientSecret' => 'oidc-prompt-secret',
            'wellKnownURL' => 'https://idp.example.com/.well-known/openid-configuration',
            'prompt' => ['login', 'consent'],
            'maxAge' => 3600,
            'enabled' => false,
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(['login', 'consent'], $response['body']['prompt']);
        $this->assertSame(3600, $response['body']['maxAge']);

        // GET reads back prompt + maxAge while hiding the clientSecret.
        $get = $this->getOAuth2Provider('oidc');
        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertSame(['login', 'consent'], $get['body']['prompt']);
        $this->assertSame(3600, $get['body']['maxAge']);
        $this->assertSame('', $get['body']['clientSecret']);

        // Cleanup
        $this->updateOAuth2('oidc', [
            'clientId' => '',
            'clientSecret' => '',
            'wellKnownURL' => '',
            'authorizationURL' => '',
            'tokenURL' => '',
            'userInfoURL' => '',
            'prompt' => [],
            'enabled' => false,
        ]);
    }

    public function testUpdateOAuth2OidcPartialPreservesPromptAndMaxAge(): void
    {
        // Seed prompt + maxAge.
        $this->updateOAuth2('oidc', [
            'clientId' => 'oidc-seed-client',
            'clientSecret' => 'oidc-seed-secret',
            'wellKnownURL' => 'https://idp.example.com/.well-known/openid-configuration',
            'prompt' => ['select_account'],
            'maxAge' => 120,
            'enabled' => false,
        ]);

        // Update only clientId — prompt and maxAge must be preserved.
        $response = $this->updateOAuth2('oidc', [
            'clientId' => 'oidc-rotated-client',
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('oidc-rotated-client', $response['body']['clientId']);
        $this->assertSame(['select_account'], $response['body']['prompt']);
        $this->assertSame(120, $response['body']['maxAge']);

        // Cleanup
        $this->updateOAuth2('oidc', [
            'clientId' => '',
            'clientSecret' => '',
            'wellKnownURL' => '',
            'prompt' => [],
            'enabled' => false,
        ]);
    }

    public function testUpdateOAuth2OidcPromptNoneAloneRejected(): void
    {
        $response = $this->updateOAuth2('oidc', [
            'clientId' => 'oidc-prompt-none',
            'clientSecret' => 'oidc-prompt-none-secret',
            'prompt' => ['none', 'consent'],
            'enabled' => false,
        ]);

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $response['body']['type']);
    }

    public function testUpdateOAuth2OidcMaxAgeNegativeRejected(): void
    {
        $response = $this->updateOAuth2('oidc', [
            'clientId' => 'oidc-maxage-negative',
            'clientSecret' => 'oidc-maxage-negative-secret',
            'maxAge' => -1,
            'enabled' => false,
        ]);

        $this->assertSame(400, $response['headers']['status-code']);
    }
}
