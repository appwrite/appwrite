<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\OAuth2;

use Appwrite\Auth\OAuth2\Etsy;
use PHPUnit\Framework\TestCase;
use Utopia\Client\Adapter\Curl\Client as CurlAdapter;
use Utopia\Client\Client;

final class EtsyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \putenv('_APP_OPENSSL_KEY_V1=unit-test-openssl-key');
    }

    protected function tearDown(): void
    {
        \putenv('_APP_OPENSSL_KEY_V1');
        parent::tearDown();
    }

    public function testLoginURL(): void
    {
        $etsy = new Etsy(new Client(new CurlAdapter()), 'client-id', 'client-secret', 'https://example.com/callback');

        $url = $etsy->getLoginURL();

        $this->assertSame('https://www.etsy.com/oauth/connect', \strtok($url, '?'));

        \parse_str((string) \parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame('client-id', $query['client_id'] ?? null);
        $this->assertSame('https://example.com/callback', $query['redirect_uri'] ?? null);
        $this->assertSame('code', $query['response_type'] ?? null);
        $this->assertSame('S256', $query['code_challenge_method'] ?? null);
        $this->assertSame('email_r profile_r', $query['scope'] ?? null);
    }
}
