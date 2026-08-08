<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\OAuth2;

use Appwrite\Auth\OAuth2\Oidc;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class OidcTest extends TestCase
{
    private string $defaultSecret = '{
        "clientSecret": "client-secret",
        "authorizationEndpoint": "https://example.com/auth",
        "tokenEndpoint": "https://example.com/token",
        "userinfoEndpoint": "https://example.com/userinfo"
    }';

    public function testAccessToken(): void
    {
        $oidc = $this->createOidc(\json_encode([
            'access_token' => 'access-token',
            'scope' => 'openid profile email',
            'token_type' => 'bearer',
        ], JSON_THROW_ON_ERROR));

        $this->assertSame('access-token', $oidc->getAccessToken('authorization-code'));
    }

    public function testGetUserID(): void
    {
        $oidc = $this->createOidcUserInfo(\json_encode([
            'sub' => 'user-id-123',
            'email' => 'user@example.com',
            'name' => 'John Doe'
        ], JSON_THROW_ON_ERROR));

        $this->assertSame('user-id-123', $oidc->getUserID('access-token'));
    }

    public function testGetUserEmail(): void
    {
        $oidc = $this->createOidcUserInfo(\json_encode([
            'sub' => 'user-id-123',
            'email' => 'user@example.com',
            'name' => 'John Doe'
        ], JSON_THROW_ON_ERROR));

        $this->assertSame('user@example.com', $oidc->getUserEmail('access-token'));
    }

    public function testGetUserName(): void
    {
        $oidc = $this->createOidcUserInfo(\json_encode([
            'sub' => 'user-id-123',
            'email' => 'user@example.com',
            'name' => 'John Doe'
        ], JSON_THROW_ON_ERROR));

        $this->assertSame('John Doe', $oidc->getUserName('access-token'));
    }

    /**
     * When all three explicit endpoints are configured, getLoginURL must use
     * the authorizationEndpoint from the secret without hitting the network.
     */
    public function testGetLoginUrlUsesExplicitAuthorizationEndpoint(): void
    {
        $oidc = new Oidc('client-id', $this->defaultSecret, 'https://example.com/callback');

        $url = $oidc->getLoginURL();

        $this->assertStringStartsWith('https://example.com/auth?', $url);
        $this->assertStringContainsString('client_id=client-id', $url);
        $this->assertStringContainsString('response_type=code', $url);
        $this->assertStringContainsString('scope=openid+profile+email', $url);
    }

    /**
     * When only a well-known endpoint is provided (no explicit endpoints),
     * getLoginURL must fetch the discovery document to resolve the
     * authorization URL. Verify it calls the well-known URL exactly once
     * and builds the redirect using the discovered authorization_endpoint.
     */
    public function testGetLoginUrlFallsBackToWellKnownDiscovery(): void
    {
        $secret = \json_encode([
            'clientSecret' => 'client-secret',
            'wellKnownEndpoint' => 'https://idp.example.com/.well-known/openid-configuration',
        ], JSON_THROW_ON_ERROR);

        $discovery = \json_encode([
            'authorization_endpoint' => 'https://idp.example.com/oauth2/authorize',
            'token_endpoint' => 'https://idp.example.com/oauth2/token',
            'userinfo_endpoint' => 'https://idp.example.com/oauth2/userinfo',
        ], JSON_THROW_ON_ERROR);

        /** @var Oidc&MockObject $oidc */
        $oidc = $this->getMockBuilder(Oidc::class)
            ->setConstructorArgs(['client-id', $secret, 'https://example.com/callback'])
            ->onlyMethods(['request'])
            ->getMock();

        $oidc
            ->expects($this->once())
            ->method('request')
            ->with('GET', 'https://idp.example.com/.well-known/openid-configuration')
            ->willReturn($discovery);

        $url = $oidc->getLoginURL();

        $this->assertStringStartsWith('https://idp.example.com/oauth2/authorize?', $url);
        $this->assertStringContainsString('client_id=client-id', $url);
    }

    /**
     * A secret with no endpoints at all (client ID only, no well-known, no
     * explicit endpoints) must throw — confirming the incomplete-config guard
     * in console.php is necessary to prevent advertising a broken login option.
     */
    public function testGetLoginUrlThrowsWhenNoEndpointConfigured(): void
    {
        $secret = \json_encode(['clientSecret' => 'client-secret'], JSON_THROW_ON_ERROR);

        /** @var Oidc&MockObject $oidc */
        $oidc = $this->getMockBuilder(Oidc::class)
            ->setConstructorArgs(['client-id', $secret, 'https://example.com/callback'])
            ->onlyMethods(['request'])
            ->getMock();

        // request() is called once for the empty well-known URL and returns empty
        $oidc
            ->expects($this->once())
            ->method('request')
            ->willReturn('');

        $this->expectException(\Exception::class);

        $oidc->getLoginURL();
    }

    private function createOidc(string $response, string $code = 'authorization-code'): Oidc&MockObject
    {
        $oidc = $this->getMockBuilder(Oidc::class)
            ->setConstructorArgs(['client-id', $this->defaultSecret, 'https://example.com/callback'])
            ->onlyMethods(['request'])
            ->getMock();

        $oidc
            ->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://example.com/token',
                ['Content-Type: application/x-www-form-urlencoded'],
                $this->callback(function (mixed $payload) use ($code): bool {
                    if (!\is_string($payload)) {
                        return false;
                    }

                    \parse_str($payload, $params);

                    $this->assertSame([
                        'code' => $code,
                        'client_id' => 'client-id',
                        'client_secret' => 'client-secret',
                        'redirect_uri' => 'https://example.com/callback',
                        'scope' => 'openid profile email',
                        'grant_type' => 'authorization_code',
                    ], $params);

                    return true;
                }),
            )
            ->willReturn($response);

        return $oidc;
    }

    private function createOidcUserInfo(string $response): Oidc&MockObject
    {
        $oidc = $this->getMockBuilder(Oidc::class)
            ->setConstructorArgs(['client-id', $this->defaultSecret, 'https://example.com/callback'])
            ->onlyMethods(['request'])
            ->getMock();

        $oidc
            ->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                'https://example.com/userinfo',
                ['Authorization: Bearer access-token']
            )
            ->willReturn($response);

        return $oidc;
    }
}
