<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\OAuth2;

use Appwrite\Auth\OAuth2\Cloudflare;
use Appwrite\Auth\OAuth2\Exception;
use Appwrite\Extend\Exception as AppwriteException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class CloudflareTest extends TestCase
{
    private const CLIENT_ID = '8c33c3da9e8f392k71m1f9dc1a190cb3707ad27ba4d19bff45c900e6dfet1f4a';
    private const CLIENT_SECRET_BLOB = '{"clientSecret":"2d106b111a390d9692ab9a8a295ac05668632b17bbb342d149209aaaaa100000","team":"acme"}';
    private const CALLBACK = 'https://example.com/callback';
    private const TOKEN_URL = 'https://acme.cloudflareaccess.com/cdn-cgi/access/sso/oidc/' . self::CLIENT_ID . '/token';

    public function testAccessToken(): void
    {
        $cloudflare = $this->createCloudflare(\json_encode([
            'access_token' => 'access-token',
            'id_token' => 'id-token',
            'refresh_token' => 'refresh-token',
            'scope' => 'openid email profile',
            'token_type' => 'bearer',
            'expires_in' => 900,
        ], JSON_THROW_ON_ERROR));

        $this->assertSame('access-token', $cloudflare->getAccessToken('authorization-code'));
    }

    public function testProviderError(): void
    {
        $cloudflare = $this->createCloudflare(\json_encode([
            'error' => 'invalid_grant',
            'error_description' => 'The code passed is incorrect or expired.',
        ], JSON_THROW_ON_ERROR), 'expired-code');

        try {
            $cloudflare->getAccessToken('expired-code');
            $this->fail('Expected the Cloudflare OAuth2 provider error to be thrown.');
        } catch (Exception $exception) {
            $this->assertSame(AppwriteException::USER_OAUTH2_BAD_REQUEST, $exception->getType());
            $this->assertSame('invalid_grant', $exception->getError());
            $this->assertSame('The code passed is incorrect or expired.', $exception->getErrorDescription());
        }
    }

    public function testFormEncodedProviderError(): void
    {
        $cloudflare = $this->createCloudflare(
            'error=invalid_grant&error_description=The+code+passed+is+incorrect+or+expired.',
            'expired-code',
        );

        try {
            $cloudflare->getAccessToken('expired-code');
            $this->fail('Expected the form-encoded Cloudflare OAuth2 provider error to be thrown.');
        } catch (Exception $exception) {
            $this->assertSame(AppwriteException::USER_OAUTH2_BAD_REQUEST, $exception->getType());
            $this->assertSame('invalid_grant', $exception->getError());
            $this->assertSame('The code passed is incorrect or expired.', $exception->getErrorDescription());
        }
    }

    public function testMissingAccessToken(): void
    {
        $cloudflare = $this->createCloudflare('{}');

        try {
            $cloudflare->getAccessToken('authorization-code');
            $this->fail('Expected a missing access token error to be thrown.');
        } catch (Exception $exception) {
            $this->assertSame(AppwriteException::USER_OAUTH2_BAD_REQUEST, $exception->getType());
            $this->assertSame('access_token_missing', $exception->getError());
        }
    }

    public function testProviderFailure(): void
    {
        $previous = new Exception(\json_encode([
            'error' => 'invalid_grant',
            'error_description' => 'The code passed is incorrect or expired.',
        ], JSON_THROW_ON_ERROR), 400);

        $exception = new AppwriteException(
            AppwriteException::USER_OAUTH2_PROVIDER_FAILURE,
            previous: $previous,
            params: ['Cloudflare', $previous->getError()],
        );

        $this->assertSame(AppwriteException::USER_OAUTH2_PROVIDER_FAILURE, $exception->getType());
        $this->assertSame(424, $exception->getCode());
        $this->assertSame(
            "Cloudflare couldn't complete sign-in (invalid_grant). Please try again.",
            $exception->getMessage(),
        );
    }

    private function createCloudflare(string $response, string $code = 'authorization-code'): Cloudflare&MockObject
    {
        $cloudflare = $this->getMockBuilder(Cloudflare::class)
            ->setConstructorArgs([self::CLIENT_ID, self::CLIENT_SECRET_BLOB, self::CALLBACK])
            ->onlyMethods(['request'])
            ->getMock();

        $cloudflare->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                self::TOKEN_URL,
                ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
                $this->callback(function (mixed $payload) use ($code): bool {
                    if (!\is_string($payload)) {
                        return false;
                    }
                    \parse_str($payload, $params);
                    $this->assertSame('authorization_code', $params['grant_type']);
                    $this->assertSame($code, $params['code']);
                    $this->assertSame(self::CALLBACK, $params['redirect_uri']);
                    $this->assertSame(self::CLIENT_ID, $params['client_id']);
                    $this->assertSame('2d106b111a390d9692ab9a8a295ac05668632b17bbb342d149209aaaaa100000', $params['client_secret']);
                    return true;
                }),
            )
            ->willReturn($response);

        return $cloudflare;
    }
}
