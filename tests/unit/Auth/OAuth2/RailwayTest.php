<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\OAuth2;

use Appwrite\Auth\OAuth2\Railway;
use PHPUnit\Framework\TestCase;
use Utopia\Client\Adapter\Curl\Client as CurlAdapter;
use Utopia\Client\Client;

final class RailwayTest extends TestCase
{
    protected function setUp(): void
    {
        // The PKCE verifier is encrypted before it is placed in the state.
        \putenv('_APP_OPENSSL_KEY_V1=unit-test-openssl-key');
    }

    protected function tearDown(): void
    {
        \putenv('_APP_OPENSSL_KEY_V1');
    }

    public function testLoginURL(): void
    {
        $railway = new Railway(new Client(new CurlAdapter()), 'client-id', 'client-secret', 'https://example.com/callback');

        $url = \parse_url($railway->getLoginURL());
        \parse_str((string) ($url['query'] ?? ''), $query);

        $this->assertSame('backboard.railway.com', $url['host']);
        $this->assertSame('/oauth/auth', $url['path']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('client-id', $query['client_id']);
        $this->assertSame('https://example.com/callback', $query['redirect_uri']);
        $this->assertSame('openid email profile offline_access', $query['scope']);
        $this->assertSame('consent', $query['prompt']);
    }
}
