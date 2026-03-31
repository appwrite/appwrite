<?php

namespace Tests\Unit\Auth\OAuth2;

use Appwrite\Auth\OAuth2\Authentik;
use PHPUnit\Framework\TestCase;

class AuthentikTest extends TestCase
{
    public function test_get_login_url_uses_authorize_path_with_trailing_slash(): void
    {
        $oauth2 = new Authentik(
            appId: 'client-id',
            appSecret: \json_encode(['authentikDomain' => 'auth.example.com'], JSON_THROW_ON_ERROR),
            callback: 'https://app.example.com/callback',
            state: ['success' => 'https://app.example.com/success'],
        );

        $url = $oauth2->getLoginURL();
        $parsed = \parse_url($url);

        $this->assertNotFalse($parsed);
        $this->assertSame('https', $parsed['scheme'] ?? null);
        $this->assertSame('auth.example.com', $parsed['host'] ?? null);
        $this->assertSame('/application/o/authorize/', $parsed['path'] ?? null);

        $query = [];
        \parse_str($parsed['query'] ?? '', $query);

        $this->assertSame('client-id', $query['client_id'] ?? null);
        $this->assertSame('https://app.example.com/callback', $query['redirect_uri'] ?? null);
        $this->assertSame('code', $query['response_type'] ?? null);
        $this->assertSame('openid profile email offline_access', $query['scope'] ?? null);
        $this->assertSame('{"success":"https:\/\/app.example.com\/success"}', $query['state'] ?? null);
    }
}
