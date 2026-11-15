<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\OAuth2;

use Appwrite\Auth\OAuth2\Gitlab;
use PHPUnit\Framework\TestCase;
use Utopia\Client\Adapter\Curl\Client as CurlAdapter;
use Utopia\Client\Client;

final class GitlabTest extends TestCase
{
    public function testSignInScopes(): void
    {
        $gitlab = $this->gitlab();

        \parse_str((string) \parse_url($gitlab->getLoginURL(), PHP_URL_QUERY), $query);

        $this->assertSame('read_user openid email', $query['scope']);
    }

    public function testConstructorScopesAreAppended(): void
    {
        $gitlab = $this->gitlab(['read_api']);

        \parse_str((string) \parse_url($gitlab->getLoginURL(), PHP_URL_QUERY), $query);

        $this->assertSame('read_user openid email read_api', $query['scope']);
    }

    public function testSetScopesReplacesConstructorScopes(): void
    {
        $gitlab = $this->gitlab(['read_api'])->setScopes([
            'read_user',
            'api',
        ]);

        \parse_str((string) \parse_url($gitlab->getLoginURL(), PHP_URL_QUERY), $query);

        $this->assertSame('read_user api', $query['scope']);
    }

    /**
     * @param array<int, string> $scopes
     */
    private function gitlab(array $scopes = []): Gitlab
    {
        return new Gitlab(
            new Client(new CurlAdapter()),
            'client-id',
            \json_encode([
                'clientSecret' => 'client-secret',
                'endpoint' => 'https://gitlab.com',
            ]),
            'https://example.com/callback',
            [],
            $scopes,
        );
    }
}
