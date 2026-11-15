<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\OAuth2;

use Appwrite\Auth\OAuth2\Gitlab;
use PHPUnit\Framework\TestCase;
use Utopia\Client\Adapter\Curl\Client as CurlAdapter;
use Utopia\Client\Client;

final class GitlabTest extends TestCase
{
    public function testSignInLoginURLRequestsOpenIdScopes(): void
    {
        $gitlab = $this->gitlab();

        $this->assertSame(
            'https://gitlab.com/oauth/authorize?client_id=client-id&redirect_uri=https%3A%2F%2Fexample.com%2Fcallback&scope=read_user+openid+email&state=%5B%5D&response_type=code',
            $gitlab->getLoginURL(),
        );
    }

    public function testCustomScopesAreAddedToSignInScopes(): void
    {
        $gitlab = $this->gitlab(scopes: ['read_api']);

        \parse_str((string) \parse_url($gitlab->getLoginURL(), PHP_URL_QUERY), $query);

        $this->assertSame('read_user openid email read_api', $query['scope']);
    }

    public function testInstallationScopesReplaceSignInScopes(): void
    {
        $gitlab = $this->gitlab()->setScopes([
            'read_user',
            'api',
        ]);

        $url = $gitlab->getLoginURL();

        $this->assertSame(
            'https://gitlab.com/oauth/authorize?client_id=client-id&redirect_uri=https%3A%2F%2Fexample.com%2Fcallback&scope=read_user+api&state=%5B%5D&response_type=code',
            $url,
        );

        \parse_str((string) \parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame('read_user api', $query['scope']);
        $this->assertStringNotContainsString(',', (string) $query['scope']);
    }

    public function testLoginURLUsesConfiguredEndpoint(): void
    {
        $gitlab = $this->gitlab('https://gitlab.example.com')->setScopes([
            'read_user',
            'api',
        ]);

        $this->assertStringStartsWith('https://gitlab.example.com/oauth/authorize?', $gitlab->getLoginURL());
    }

    /**
     * @param array<int, string> $scopes
     */
    private function gitlab(string $endpoint = 'https://gitlab.com', array $scopes = []): Gitlab
    {
        return new Gitlab(
            new Client(new CurlAdapter()),
            'client-id',
            \json_encode([
                'clientSecret' => 'client-secret',
                'endpoint' => $endpoint,
            ]),
            'https://example.com/callback',
            [],
            $scopes,
        );
    }
}
