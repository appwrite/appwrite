<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\OAuth2;

use Appwrite\Auth\OAuth2\Exception;
use Appwrite\Auth\OAuth2\Oidc;
use Appwrite\Extend\Exception as AppwriteException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class OidcTest extends TestCase
{
    private const SECRET = '{"clientSecret":"secret","authorizationEndpoint":"https://idp.example/authorize","tokenEndpoint":"https://idp.example/token","userInfoEndpoint":"https://idp.example/userinfo"}';

    public function testAccessTokenFromJson(): void
    {
        $oidc = $this->createOidc([
            [
                'method' => 'POST',
                'url' => 'https://idp.example/token',
                'response' => \json_encode([
                    'access_token' => 'access-token',
                    'token_type' => 'Bearer',
                    'expires_in' => 3600,
                ], JSON_THROW_ON_ERROR),
            ],
        ]);

        $this->assertSame('access-token', $oidc->getAccessToken('authorization-code'));
    }

    public function testEmptyTokenResponseThrows(): void
    {
        $oidc = $this->createOidc([
            [
                'method' => 'POST',
                'url' => 'https://idp.example/token',
                // Cloudflare Access error shape: HTTP 302, empty body, no JSON
                'response' => '',
            ],
        ]);

        try {
            $oidc->getAccessToken('authorization-code');
            $this->fail('Expected an empty token response to throw.');
        } catch (Exception $exception) {
            $this->assertSame(AppwriteException::USER_OAUTH2_BAD_REQUEST, $exception->getType());
            $this->assertSame('token_response_empty', $exception->getError());
        }
    }

    public function testProviderErrorJsonThrows(): void
    {
        $oidc = $this->createOidc([
            [
                'method' => 'POST',
                'url' => 'https://idp.example/token',
                'response' => \json_encode([
                    'error' => 'invalid_client',
                    'error_description' => 'Invalid client secret',
                ], JSON_THROW_ON_ERROR),
            ],
        ]);

        try {
            $oidc->getAccessToken('authorization-code');
            $this->fail('Expected a provider error to throw.');
        } catch (Exception $exception) {
            $this->assertSame(AppwriteException::USER_OAUTH2_BAD_REQUEST, $exception->getType());
            $this->assertSame('invalid_client', $exception->getError());
            $this->assertSame('Invalid client secret', $exception->getErrorDescription());
        }
    }

    public function testIdentityFromIdTokenWhenUserinfoAbsent(): void
    {
        $idToken = $this->mintIdToken([
            'sub' => 'cf-user-1',
            'email' => 'user@example.com',
            'name' => 'Ada Lovelace',
        ]);

        $oidc = $this->createOidc([
            [
                'method' => 'POST',
                'url' => 'https://idp.example/token',
                'response' => \json_encode([
                    'access_token' => 'access-token',
                    'id_token' => $idToken,
                    'token_type' => 'Bearer',
                ], JSON_THROW_ON_ERROR),
            ],
            [
                'method' => 'GET',
                'url' => 'https://idp.example/userinfo',
                'response' => '',
                'exception' => new Exception(\json_encode([
                    'error' => 'invalid_token',
                    'error_description' => 'Userinfo unavailable',
                ], JSON_THROW_ON_ERROR), 401),
            ],
        ]);

        $accessToken = $oidc->getAccessToken('authorization-code');
        $this->assertSame('access-token', $accessToken);
        $this->assertSame('cf-user-1', $oidc->getUserID($accessToken));
        $this->assertSame('user@example.com', $oidc->getUserEmail($accessToken));
        $this->assertSame('Ada Lovelace', $oidc->getUserName($accessToken));
        // Missing email_verified + present email → verified for admin-configured OIDC
        $this->assertTrue($oidc->isEmailVerified($accessToken));
    }

    public function testEmailVerifiedStringTrue(): void
    {
        $idToken = $this->mintIdToken([
            'sub' => 'user-1',
            'email' => 'user@example.com',
            'email_verified' => 'true',
        ]);

        $oidc = $this->createOidc([
            [
                'method' => 'POST',
                'url' => 'https://idp.example/token',
                'response' => \json_encode([
                    'access_token' => 'access-token',
                    'id_token' => $idToken,
                ], JSON_THROW_ON_ERROR),
            ],
            [
                'method' => 'GET',
                'url' => 'https://idp.example/userinfo',
                'response' => \json_encode([
                    'sub' => 'user-1',
                    'email' => 'user@example.com',
                    'email_verified' => 'true',
                ], JSON_THROW_ON_ERROR),
            ],
        ]);

        $accessToken = $oidc->getAccessToken('authorization-code');
        $this->assertTrue($oidc->isEmailVerified($accessToken));
    }

    public function testEmailVerifiedExplicitFalseBlocks(): void
    {
        $idToken = $this->mintIdToken([
            'sub' => 'user-1',
            'email' => 'user@example.com',
            'email_verified' => false,
        ]);

        $oidc = $this->createOidc([
            [
                'method' => 'POST',
                'url' => 'https://idp.example/token',
                'response' => \json_encode([
                    'access_token' => 'access-token',
                    'id_token' => $idToken,
                ], JSON_THROW_ON_ERROR),
            ],
            [
                'method' => 'GET',
                'url' => 'https://idp.example/userinfo',
                'response' => \json_encode([
                    'sub' => 'user-1',
                    'email' => 'user@example.com',
                    'email_verified' => false,
                ], JSON_THROW_ON_ERROR),
            ],
        ]);

        $accessToken = $oidc->getAccessToken('authorization-code');
        $this->assertFalse($oidc->isEmailVerified($accessToken));
    }

    public function testUserinfoOverridesIdTokenClaims(): void
    {
        $idToken = $this->mintIdToken([
            'sub' => 'from-id-token',
            'email' => 'id-token@example.com',
            'name' => 'From ID Token',
        ]);

        $oidc = $this->createOidc([
            [
                'method' => 'POST',
                'url' => 'https://idp.example/token',
                'response' => \json_encode([
                    'access_token' => 'access-token',
                    'id_token' => $idToken,
                ], JSON_THROW_ON_ERROR),
            ],
            [
                'method' => 'GET',
                'url' => 'https://idp.example/userinfo',
                'response' => \json_encode([
                    'sub' => 'from-userinfo',
                    'email' => 'userinfo@example.com',
                    'name' => 'From Userinfo',
                    'email_verified' => true,
                ], JSON_THROW_ON_ERROR),
            ],
        ]);

        $accessToken = $oidc->getAccessToken('authorization-code');
        $this->assertSame('from-userinfo', $oidc->getUserID($accessToken));
        $this->assertSame('userinfo@example.com', $oidc->getUserEmail($accessToken));
        $this->assertSame('From Userinfo', $oidc->getUserName($accessToken));
        $this->assertTrue($oidc->isEmailVerified($accessToken));
    }

    public function testIdTokenAloneIsEnough(): void
    {
        $idToken = $this->mintIdToken([
            'sub' => 'id-only',
            'email' => 'only@example.com',
        ]);

        $oidc = $this->createOidc([
            [
                'method' => 'POST',
                'url' => 'https://idp.example/token',
                'response' => \json_encode([
                    'id_token' => $idToken,
                    'token_type' => 'Bearer',
                ], JSON_THROW_ON_ERROR),
            ],
        ]);

        $accessToken = $oidc->getAccessToken('authorization-code');
        $this->assertSame('', $accessToken);
        $this->assertSame('id-only', $oidc->getUserID($accessToken));
        $this->assertSame('only@example.com', $oidc->getUserEmail($accessToken));
        $this->assertTrue($oidc->isEmailVerified($accessToken));
    }

    /**
     * @param array<int, array{method: string, url: string, response?: string, exception?: Exception}> $calls
     */
    private function createOidc(array $calls): Oidc&MockObject
    {
        $oidc = $this->getMockBuilder(Oidc::class)
            ->setConstructorArgs(['client-id', self::SECRET, 'https://example.com/callback'])
            ->onlyMethods(['request'])
            ->getMock();

        $oidc
            ->expects($this->exactly(\count($calls)))
            ->method('request')
            ->willReturnCallback(function (string $method, string $url = '', array $headers = [], string $payload = '') use (&$calls): string {
                $expected = \array_shift($calls);
                $this->assertNotNull($expected);
                $this->assertSame($expected['method'], $method);
                $this->assertSame($expected['url'], $url);

                if (isset($expected['exception'])) {
                    throw $expected['exception'];
                }

                return $expected['response'] ?? '';
            });

        return $oidc;
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function mintIdToken(array $claims): string
    {
        $header = $this->base64UrlEncode(\json_encode(['alg' => 'none', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $payload = $this->base64UrlEncode(\json_encode($claims, JSON_THROW_ON_ERROR));

        return $header . '.' . $payload . '.';
    }

    private function base64UrlEncode(string $data): string
    {
        return \rtrim(\strtr(\base64_encode($data), '+/', '-_'), '=');
    }
}
