<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\OAuth2;

use Appwrite\Auth\OAuth2\Supabase;
use PHPUnit\Framework\TestCase;
use Utopia\Client\Adapter\Curl\Client as CurlAdapter;
use Utopia\Client\Client;

final class SupabaseTest extends TestCase
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
        $supabase = new Supabase(new Client(new CurlAdapter()), 'client-id', 'client-secret', 'https://example.com/callback');

        $url = \parse_url($supabase->getLoginURL());
        \parse_str((string) ($url['query'] ?? ''), $query);

        $this->assertSame('api.supabase.com', $url['host']);
        $this->assertSame('/v1/oauth/authorize', $url['path']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('client-id', $query['client_id']);
        $this->assertSame('https://example.com/callback', $query['redirect_uri']);
        $this->assertSame('S256', $query['code_challenge_method']);
    }
}
