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
        \putenv('_APP_OPENSSL_KEY_V1=unit-test-openssl-key');
    }

    protected function tearDown(): void
    {
        \putenv('_APP_OPENSSL_KEY_V1');
    }

    public function testGetName(): void
    {
        $etsy = new Etsy(new Client(new CurlAdapter()), 'test-keystring', 'test-shared-secret', 'https://example.com/callback');

        $this->assertSame('etsy', $etsy->getName());
    }

    public function testLoginURLContainsRequiredParameters(): void
    {
        $etsy = new Etsy(
            new Client(new CurlAdapter()),
            'test-keystring',
            'test-shared-secret',
            'https://example.com/callback',
            ['state-token' => 'xyz']
        );

        $loginUrl = $etsy->getLoginURL();
        $this->assertStringStartsWith('https://www.etsy.com/oauth/connect?', $loginUrl);

        \parse_str((string) \parse_url($loginUrl, PHP_URL_QUERY), $query);

        $this->assertSame('test-keystring', $query['client_id'] ?? null);
        $this->assertSame('https://example.com/callback', $query['redirect_uri'] ?? null);
        $this->assertSame('code', $query['response_type'] ?? null);
        $this->assertSame('S256', $query['code_challenge_method'] ?? null);
        $this->assertNotEmpty($query['code_challenge'] ?? null);
        $this->assertNotEmpty($query['state'] ?? null);

        $stateData = $etsy->parseState($query['state']);
        $this->assertIsArray($stateData);
        $this->assertSame('xyz', $stateData['state-token'] ?? null);
    }

    public function testTokenExchangeRequestAndResponse(): void
    {
        $etsy = new FakeEtsy(new Client(new CurlAdapter()), 'test-keystring', 'test-shared-secret', 'https://example.com/callback');

        $accessToken = $etsy->getAccessToken('auth-code');
        $refreshToken = $etsy->getRefreshToken('auth-code');
        $expiry = $etsy->getAccessTokenExpiry('auth-code');

        $this->assertSame('12345.mock-access-token', $accessToken);
        $this->assertSame('mock-refresh-token', $refreshToken);
        $this->assertSame(3600, $expiry);

        $this->assertSame('POST', $etsy->lastMethod);
        $this->assertSame('https://api.etsy.com/v3/public/oauth/token', $etsy->lastUrl);
        $this->assertContains('Content-Type: application/x-www-form-urlencoded', $etsy->lastHeaders);

        \parse_str($etsy->lastPayload, $params);
        $this->assertSame('authorization_code', $params['grant_type'] ?? null);
        $this->assertSame('test-keystring', $params['client_id'] ?? null);
        $this->assertSame('https://example.com/callback', $params['redirect_uri'] ?? null);
        $this->assertSame('auth-code', $params['code'] ?? null);
        $this->assertNotEmpty($params['code_verifier'] ?? null);
    }

    public function testRefreshTokenRequest(): void
    {
        $etsy = new FakeEtsy(new Client(new CurlAdapter()), 'test-keystring', 'test-shared-secret', 'https://example.com/callback');

        $tokens = $etsy->refreshTokens('old-refresh-token');

        $this->assertSame('POST', $etsy->lastMethod);
        $this->assertSame('https://api.etsy.com/v3/public/oauth/token', $etsy->lastUrl);
        $this->assertContains('Content-Type: application/x-www-form-urlencoded', $etsy->lastHeaders);

        \parse_str($etsy->lastPayload, $params);
        $this->assertSame('refresh_token', $params['grant_type'] ?? null);
        $this->assertSame('test-keystring', $params['client_id'] ?? null);
        $this->assertSame('old-refresh-token', $params['refresh_token'] ?? null);

        $this->assertSame('mock-refreshed-token', $tokens['access_token'] ?? null);
    }

    public function testGetUserSendsRequiredHeaders(): void
    {
        $etsy = new FakeEtsy(
            new Client(new CurlAdapter()),
            'test-keystring',
            'test-shared-secret',
            'https://example.com/callback'
        );

        $email = $etsy->getUserEmail('98765.mock-token');

        $this->assertSame('user@example.com', $email);
        $this->assertSame('GET', $etsy->lastMethod);
        $this->assertSame('https://api.etsy.com/v3/application/users/98765', $etsy->lastUrl);
        $this->assertContains('Authorization: Bearer 98765.mock-token', $etsy->lastHeaders);
        $this->assertContains('x-api-key: test-keystring:test-shared-secret', $etsy->lastHeaders);
    }
}

class FakeEtsy extends Etsy
{
    public string $lastMethod = '';
    public string $lastUrl = '';
    public array $lastHeaders = [];
    public string $lastPayload = '';

    protected function request(string $method, string $url = '', array $headers = [], string $payload = ''): string
    {
        $this->lastMethod = $method;
        $this->lastUrl = $url;
        $this->lastHeaders = $headers;
        $this->lastPayload = $payload;

        if (\str_contains($url, '/oauth/token')) {
            if (\str_contains($payload, 'grant_type=refresh_token')) {
                return \json_encode([
                    'access_token' => 'mock-refreshed-token',
                    'refresh_token' => 'mock-new-refresh-token',
                    'expires_in' => 3600,
                ]);
            }

            return \json_encode([
                'access_token' => '12345.mock-access-token',
                'refresh_token' => 'mock-refresh-token',
                'expires_in' => 3600,
            ]);
        }

        if (\str_contains($url, '/v3/application/users/')) {
            return \json_encode([
                'user_id' => 98765,
                'primary_email' => 'user@example.com',
                'login_name' => 'etsyuser',
                'image_url_75x75' => 'https://i.etsystatic.com/profile.jpg',
            ]);
        }

        return '';
    }
}
