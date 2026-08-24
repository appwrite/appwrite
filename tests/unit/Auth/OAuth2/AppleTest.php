<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\OAuth2;

use Appwrite\Auth\OAuth2\Apple;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class AppleTest extends TestCase
{
    public function testVerifyCredentials(): void
    {
        $apple = new Apple('com.example.service', $this->secret($this->privateKey()), 'https://example.com/callback');

        $apple->verifyCredentials();

        $this->expectNotToPerformAssertions();
    }

    public function testVerifyCredentialsSingleLinePem(): void
    {
        // The console field example documents a single-line PEM, which OpenSSL
        // alone cannot parse. It must be accepted.
        $apple = new Apple('com.example.service', $this->secret($this->singleLine($this->privateKey())), 'https://example.com/callback');

        $apple->verifyCredentials();

        $this->expectNotToPerformAssertions();
    }

    public function testVerifyCredentialsEscapedNewlines(): void
    {
        // Keys copied out of JSON or env files carry literal \n sequences.
        $escaped = \str_replace("\n", '\\n', $this->privateKey());
        $apple = new Apple('com.example.service', $this->secret($escaped), 'https://example.com/callback');

        $apple->verifyCredentials();

        $this->expectNotToPerformAssertions();
    }

    public function testVerifyCredentialsInvalidKey(): void
    {
        $apple = new Apple('com.example.service', $this->secret('-----BEGIN PRIVATE KEY-----not-a-key-----END PRIVATE KEY-----'), 'https://example.com/callback');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('p8');

        $apple->verifyCredentials();
    }

    public function testVerifyCredentialsMissingKeyId(): void
    {
        $secret = \json_encode(['p8' => $this->privateKey(), 'keyID' => '', 'teamID' => 'D4000000R6'], JSON_THROW_ON_ERROR);
        $apple = new Apple('com.example.service', $secret, 'https://example.com/callback');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Key ID');

        $apple->verifyCredentials();
    }

    public function testVerifyCredentialsMissingTeamId(): void
    {
        $secret = \json_encode(['p8' => $this->privateKey(), 'keyID' => 'P4000000N8', 'teamID' => ''], JSON_THROW_ON_ERROR);
        $apple = new Apple('com.example.service', $secret, 'https://example.com/callback');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Team ID');

        $apple->verifyCredentials();
    }

    public function testVerifyCredentialsInvalidSecret(): void
    {
        $apple = new Apple('com.example.service', 'not-json', 'https://example.com/callback');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid secret');

        $apple->verifyCredentials();
    }

    public function testTokenRequestSendsSignedClientSecret(): void
    {
        $pem = $this->privateKey();
        $clientSecret = null;

        $apple = $this->createApple($this->secret($this->singleLine($pem)), function (string $payload) use (&$clientSecret): bool {
            \parse_str($payload, $params);
            $clientSecret = $params['client_secret'] ?? null;

            return true;
        });

        $this->assertSame('access-token', $apple->getAccessToken('authorization-code'));
        $this->assertIsString($clientSecret);

        $segments = \explode('.', $clientSecret);
        $this->assertCount(3, $segments);

        $header = \json_decode(\base64_decode(\strtr($segments[0], '-_', '+/')), true);
        $this->assertSame('ES256', $header['alg']);
        $this->assertSame('P4000000N8', $header['kid']);

        $claims = \json_decode(\base64_decode(\strtr($segments[1], '-_', '+/')), true);
        $this->assertSame('D4000000R6', $claims['iss']);
        $this->assertSame('com.example.service', $claims['sub']);
        $this->assertSame('https://appleid.apple.com', $claims['aud']);

        // Raw ES256 signatures are R||S, 32 bytes each.
        $this->assertSame(64, \strlen(\base64_decode(\strtr($segments[2], '-_', '+/'))));
    }

    public function testTokenRequestRejectsInvalidKey(): void
    {
        // An unusable key must fail loudly before anything is sent to Apple --
        // never as an empty client_secret that Apple rejects as invalid_client.
        $apple = $this->getMockBuilder(Apple::class)
            ->setConstructorArgs(['com.example.service', $this->secret('garbage'), 'https://example.com/callback'])
            ->onlyMethods(['request'])
            ->getMock();

        $apple
            ->expects($this->never())
            ->method('request');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('p8');

        $apple->getAccessToken('authorization-code');
    }

    private function createApple(string $secret, callable $assertPayload): Apple&MockObject
    {
        $apple = $this->getMockBuilder(Apple::class)
            ->setConstructorArgs(['com.example.service', $secret, 'https://example.com/callback'])
            ->onlyMethods(['request'])
            ->getMock();

        $apple
            ->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://appleid.apple.com/auth/token',
                ['Content-Type: application/x-www-form-urlencoded'],
                $this->callback($assertPayload),
            )
            ->willReturn(\json_encode(['access_token' => 'access-token'], JSON_THROW_ON_ERROR));

        return $apple;
    }

    private function privateKey(): string
    {
        $resource = \openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        \openssl_pkey_export($resource, $pem);

        return $pem;
    }

    private function singleLine(string $pem): string
    {
        $body = \str_replace(['-----BEGIN PRIVATE KEY-----', '-----END PRIVATE KEY-----', "\n", "\r"], '', $pem);

        return '-----BEGIN PRIVATE KEY-----' . $body . '-----END PRIVATE KEY-----';
    }

    private function secret(string $p8): string
    {
        return \json_encode([
            'p8' => $p8,
            'keyID' => 'P4000000N8',
            'teamID' => 'D4000000R6',
        ], JSON_THROW_ON_ERROR);
    }
}
