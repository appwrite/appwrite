<?php

namespace Tests\Unit\Auth\OAuth2;

use Appwrite\Auth\OAuth2\Authentik;
use PHPUnit\Framework\TestCase;

class AuthentikTest extends TestCase
{
    public function testLoginURLUsesTrailingSlashAuthorizePath(): void
    {
        $provider = new Authentik(
            appId: 'client-id',
            appSecret: \json_encode([
                'clientSecret' => 'client-secret',
                'authentikDomain' => 'auth.example.test',
            ], JSON_THROW_ON_ERROR),
            callback: 'https://appwrite.test/v1/account/sessions/oauth2/callback/authentik/project-id',
            state: ['project' => 'project-id'],
        );

        $url = $provider->getLoginURL();
        $parts = \parse_url($url);

        $this->assertSame('https', $parts['scheme'] ?? null);
        $this->assertSame('auth.example.test', $parts['host'] ?? null);
        $this->assertSame('/application/o/authorize/', $parts['path'] ?? null);
        $this->assertNotEmpty($parts['query'] ?? null);

        \parse_str($parts['query'], $query);

        $this->assertSame('client-id', $query['client_id'] ?? null);
        $this->assertSame('https://appwrite.test/v1/account/sessions/oauth2/callback/authentik/project-id', $query['redirect_uri'] ?? null);
        $this->assertSame('code', $query['response_type'] ?? null);
    }
}
