<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\OAuth2;

use Appwrite\Auth\OAuth2\Box;
use PHPUnit\Framework\TestCase;
use Utopia\Client\Adapter\Curl\Client as CurlAdapter;
use Utopia\Client\Client;

final class BoxTest extends TestCase
{
    public function testDefaultScopesAreEmptyAndLoginURLOmitsScope(): void
    {
        $box = new Box(new Client(new CurlAdapter()), 'test-client-id', 'test-client-secret', 'https://example.com/callback', ['state-key' => 'state-val']);

        $loginUrl = $box->getLoginURL();
        $this->assertStringStartsWith('https://account.box.com/api/oauth2/authorize?', $loginUrl);

        \parse_str((string) \parse_url($loginUrl, PHP_URL_QUERY), $query);

        $this->assertSame('code', $query['response_type'] ?? null);
        $this->assertSame('test-client-id', $query['client_id'] ?? null);
        $this->assertSame('https://example.com/callback', $query['redirect_uri'] ?? null);
        $this->assertSame(\json_encode(['state-key' => 'state-val']), $query['state'] ?? null);
        $this->assertArrayNotHasKey('scope', $query);
    }

    public function testLoginURLWithCustomScopesUsesSpaceDelimiter(): void
    {
        $box = new Box(
            new Client(new CurlAdapter()),
            'test-client-id',
            'test-client-secret',
            'https://example.com/callback',
            ['state-key' => 'state-val'],
            ['root_readonly', 'manage_webhook']
        );

        $loginUrl = $box->getLoginURL();
        \parse_str((string) \parse_url($loginUrl, PHP_URL_QUERY), $query);

        $this->assertSame('root_readonly manage_webhook', $query['scope'] ?? null);
    }

    public function testTokenExchangeRequestAndResponse(): void
    {
        $box = new FakeBox(new Client(new CurlAdapter()), 'test-client-id', 'test-client-secret', 'https://example.com/callback');

        $accessToken = $box->getAccessToken('test-code');
        $refreshToken = $box->getRefreshToken('test-code');
        $expiry = $box->getAccessTokenExpiry('test-code');

        $this->assertSame('mock-access-token', $accessToken);
        $this->assertSame('mock-refresh-token', $refreshToken);
        $this->assertSame(3600, $expiry);

        $this->assertSame('POST', $box->lastMethod);
        $this->assertSame('https://account.box.com/api/oauth2/token', $box->lastUrl);

        \parse_str($box->lastPayload, $params);
        $this->assertSame('test-client-id', $params['client_id'] ?? null);
        $this->assertSame('test-client-secret', $params['client_secret'] ?? null);
        $this->assertSame('test-code', $params['code'] ?? null);
        $this->assertSame('authorization_code', $params['grant_type'] ?? null);
        $this->assertSame('https://example.com/callback', $params['redirect_uri'] ?? null);
        $this->assertArrayNotHasKey('scope', $params);
    }

    public function testRefreshTokenRequest(): void
    {
        $box = new FakeBox(new Client(new CurlAdapter()), 'test-client-id', 'test-client-secret', 'https://example.com/callback');

        $tokens = $box->refreshTokens('old-refresh-token');

        $this->assertSame('POST', $box->lastMethod);
        $this->assertSame('https://account.box.com/api/oauth2/token', $box->lastUrl);

        \parse_str($box->lastPayload, $params);
        $this->assertSame('test-client-id', $params['client_id'] ?? null);
        $this->assertSame('test-client-secret', $params['client_secret'] ?? null);
        $this->assertSame('old-refresh-token', $params['refresh_token'] ?? null);
        $this->assertSame('refresh_token', $params['grant_type'] ?? null);

        $this->assertSame('mock-refreshed-token', $tokens['access_token'] ?? null);
    }

    public function testGetUserProfileQueriesUsersMeEndpoint(): void
    {
        $box = new FakeBox(new Client(new CurlAdapter()), 'test-client-id', 'test-client-secret', 'https://example.com/callback');

        $userId = $box->getUserID('mock-access-token');
        $email = $box->getUserEmail('mock-access-token');
        $name = $box->getUserName('mock-access-token');
        $photo = $box->getUserPhoto('mock-access-token');
        $verified = $box->isEmailVerified('mock-access-token');

        $this->assertSame('11446498', $userId);
        $this->assertSame('ceo@example.com', $email);
        $this->assertSame('Aaron Levie', $name);
        $this->assertSame('https://www.box.com/api/avatar/large/181216415', $photo);
        $this->assertFalse($verified);

        $this->assertSame('GET', $box->lastMethod);
        $this->assertSame('https://api.box.com/2.0/users/me', $box->lastUrl);
        $this->assertContains('Authorization: Bearer mock-access-token', $box->lastHeaders);
    }
}

final class FakeBox extends Box
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

        if (\str_contains($url, 'token')) {
            if (\str_contains($payload, 'grant_type=refresh_token')) {
                return \json_encode([
                    'access_token' => 'mock-refreshed-token',
                    'refresh_token' => 'mock-new-refresh-token',
                    'expires_in' => 3600,
                ]);
            }

            return \json_encode([
                'access_token' => 'mock-access-token',
                'refresh_token' => 'mock-refresh-token',
                'expires_in' => 3600,
            ]);
        }

        if (\str_contains($url, 'users/me')) {
            return \json_encode([
                'id' => '11446498',
                'name' => 'Aaron Levie',
                'login' => 'ceo@example.com',
                'avatar_url' => 'https://www.box.com/api/avatar/large/181216415',
            ]);
        }

        return '';
    }
}
