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
