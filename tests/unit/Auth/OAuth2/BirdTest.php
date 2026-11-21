<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\OAuth2;

use Appwrite\Auth\OAuth2\Bird;
use Appwrite\Auth\OAuth2\Exception;
use PHPUnit\Framework\TestCase;
use Utopia\Client\Adapter\Curl\Client as CurlAdapter;
use Utopia\Client\Client;

final class BirdTest extends TestCase
{
    private const TOKEN_URL = 'https://platform.bird.com/v1/oauth/token';
    private const WORKSPACE_URL = 'https://platform.bird.com/v1/workspace';

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
        $bird = new Bird(new Client(new CurlAdapter()), 'client-id', 'client-secret', 'https://example.com/callback');

        $url = \parse_url($bird->getLoginURL());
        \parse_str((string) ($url['query'] ?? ''), $query);

        $this->assertSame('platform.bird.com', $url['host']);
        $this->assertSame('/v1/oauth/authorize', $url['path']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('client-id', $query['client_id']);
        $this->assertSame('https://example.com/callback', $query['redirect_uri']);
        $this->assertSame('workspace:read', $query['scope']);
        $this->assertSame('S256', $query['code_challenge_method']);
    }

    public function testTokenRequestSendsClientCredentials(): void
    {
        $bird = new FakeBird([self::TOKEN_URL => ['access_token' => 'bt_us1_access-token']]);

        $this->assertSame('bt_us1_access-token', $bird->getAccessToken('authorization-code'));

        \parse_str($bird->payloads[self::TOKEN_URL], $params);
        $this->assertSame('client-id', $params['client_id'] ?? null);
        $this->assertSame('client-secret', $params['client_secret'] ?? null);
    }

    public function testRefreshKeepsTheRotatedToken(): void
    {
        $bird = new FakeBird([self::TOKEN_URL => ['access_token' => 'bt_us1_new-access', 'refresh_token' => 'rotated-refresh']]);

        $tokens = $bird->refreshTokens('consumed-refresh');

        \parse_str($bird->payloads[self::TOKEN_URL], $params);
        $this->assertSame('refresh_token', $params['grant_type'] ?? null);
        $this->assertSame('consumed-refresh', $params['refresh_token'] ?? null);
        $this->assertSame('client-secret', $params['client_secret'] ?? null);
        // Bird revokes the grant when a rotated refresh token is reused.
        $this->assertSame('rotated-refresh', $tokens['refresh_token']);
        $this->assertSame('bt_us1_new-access', $tokens['access_token']);
    }

    public function testRefreshKeepsTheCurrentTokenWhenNoneIsReturned(): void
    {
        $bird = new FakeBird([self::TOKEN_URL => ['access_token' => 'bt_us1_new-access']]);

        $tokens = $bird->refreshTokens('current-refresh');

        $this->assertSame('current-refresh', $tokens['refresh_token']);
    }

    public function testIdentityIsTheWorkspaceTheGrantIsFor(): void
    {
        $bird = new FakeBird([
            self::TOKEN_URL => [
                'access_token' => 'bt_eu1_access-token',
                'workspace_id' => 'ws_01m782jkzsegy9m4bsh89p4y6w',
                'organization_id' => 'org_01m782jkzkek08388sm52ssmjm',
                'region' => 'eu1',
            ],
            self::WORKSPACE_URL => ['id' => 'ws_01m782jkzsegy9m4bsh89p4y6w', 'name' => 'Production'],
        ]);

        $accessToken = $bird->getAccessToken('authorization-code');

        $this->assertSame('ws_01m782jkzsegy9m4bsh89p4y6w', $bird->getUserID($accessToken));
        $this->assertSame('Production', $bird->getUserName($accessToken));
        $this->assertSame('', $bird->getUserEmail($accessToken));
        $this->assertFalse($bird->isEmailVerified($accessToken));
        $this->assertSame(['Authorization: Bearer bt_eu1_access-token'], $bird->headers[self::WORKSPACE_URL]);
    }

    public function testWorkspaceIdentityIsLinkOnly(): void
    {
        // Every member of the workspace gets the same ID, so it must never sign anyone in
        $this->assertTrue((new FakeBird([]))->isLinkOnly());
    }

    public function testIdentityFallsBackToTheWorkspaceEndpoint(): void
    {
        $bird = new FakeBird([self::WORKSPACE_URL => ['id' => 'ws_01m782jkzsegy9m4bsh89p4y6w', 'name' => 'Production']]);

        $this->assertSame('ws_01m782jkzsegy9m4bsh89p4y6w', $bird->getUserID('bt_eu1_access-token'));
    }

    public function testAnUnreadableWorkspaceLeavesTheNameEmpty(): void
    {
        // The user cleared workspace:read on the consent screen.
        $bird = new FakeBird([self::TOKEN_URL => ['access_token' => 'bt_eu1_access-token', 'workspace_id' => 'ws_01m782jkzsegy9m4bsh89p4y6w']]);

        $accessToken = $bird->getAccessToken('authorization-code');

        $this->assertSame('', $bird->getUserName($accessToken));
        $this->assertSame('ws_01m782jkzsegy9m4bsh89p4y6w', $bird->getUserID($accessToken));
    }
}

final class FakeBird extends Bird
{
    /** @var array<string, array<string>> */
    public array $headers = [];

    /** @var array<string, string> */
    public array $payloads = [];

    /**
     * @param array<string, array<string, mixed>> $responses JSON body per URL; any other URL answers 403
     */
    public function __construct(private readonly array $responses)
    {
        parent::__construct(new Client(new CurlAdapter()), 'client-id', 'client-secret', 'https://example.com/callback');
    }

    protected function request(string $method, string $url = '', array $headers = [], string $payload = ''): string
    {
        $this->headers[$url] = $headers;
        $this->payloads[$url] = $payload;

        if (!isset($this->responses[$url])) {
            throw new Exception('{"error":{"type":"permission_error","message":"You do not have permission to perform this action."}}', 403);
        }

        return \json_encode($this->responses[$url], JSON_THROW_ON_ERROR);
    }
}
