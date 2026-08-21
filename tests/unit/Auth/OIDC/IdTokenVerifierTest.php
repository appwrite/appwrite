<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\OIDC;

use Appwrite\Auth\OIDC\IdTokenVerifier;
use Appwrite\Auth\OIDC\Jwks;
use Appwrite\Auth\OIDC\Profile;
use Appwrite\Auth\OIDC\VerificationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\Memory;
use Utopia\Cache\Cache;

final class IdTokenVerifierTest extends TestCase
{
    private const ISSUER = 'https://issuer.test';
    private const AUDIENCE = 'client-id.test';
    private const KID = 'test-kid';

    private static \OpenSSLAsymmetricKey $key;
    private static array $jwk;

    public static function setUpBeforeClass(): void
    {
        self::$key = \openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        $details = \openssl_pkey_get_details(self::$key);
        self::$jwk = [
            'kty' => 'RSA',
            'use' => 'sig',
            'kid' => self::KID,
            'n' => self::base64UrlEncode($details['rsa']['n']),
            'e' => self::base64UrlEncode($details['rsa']['e']),
        ];
    }

    public function testValidTokenReturnsClaims(): void
    {
        $claims = $this->verifier()->verify(
            $this->profile(),
            $this->mint(['email' => 'user@example.com']),
            [self::AUDIENCE],
            null,
        );

        $this->assertSame('subject-1', $claims['sub']);
        $this->assertSame('user@example.com', $claims['email']);
    }

    public function testAudienceMayBeAnArray(): void
    {
        $claims = $this->verifier()->verify(
            $this->profile(),
            $this->mint(['aud' => ['other-client', self::AUDIENCE]]),
            [self::AUDIENCE],
            null,
        );

        $this->assertSame('subject-1', $claims['sub']);
    }

    public function testAudienceMayMatchAnySecondaryClientId(): void
    {
        $claims = $this->verifier()->verify(
            $this->profile(),
            $this->mint(['aud' => 'com.example.bundle']),
            [self::AUDIENCE, 'com.example.bundle'],
            null,
        );

        $this->assertSame('subject-1', $claims['sub']);
    }

    /**
     * Google echoes the raw nonce; Apple clients send SHA256(raw) to the
     * provider, so the claim is the hex hash. Both conventions must verify
     * against the same raw request nonce.
     */
    public function testNonceMatchesRawOrItsSha256(): void
    {
        $raw = 'raw-nonce-value';

        $rawClaims = $this->verifier()->verify($this->profile(), $this->mint(['nonce' => $raw]), [self::AUDIENCE], $raw);
        $hashedClaims = $this->verifier()->verify($this->profile(), $this->mint(['nonce' => \hash('sha256', $raw)]), [self::AUDIENCE], $raw);

        $this->assertSame('subject-1', $rawClaims['sub']);
        $this->assertSame('subject-1', $hashedClaims['sub']);
    }

    /**
     * @return \Iterator<string, array{array<string, mixed>, ?string, string}>
     */
    public static function rejections(): \Iterator
    {
        yield 'expired' => [['exp' => \time() - 3600], null, 'Token expired'];
        yield 'missing exp' => [['exp' => null], null, 'Token expired'];
        yield 'future iat' => [['iat' => \time() + 3600], null, 'Token not yet valid'];
        yield 'future nbf' => [['nbf' => \time() + 3600], null, 'Token not yet valid'];
        yield 'wrong issuer' => [['iss' => 'https://evil.test'], null, 'Invalid issuer'];
        yield 'missing issuer' => [['iss' => null], null, 'Invalid issuer'];
        yield 'wrong audience' => [['aud' => 'someone-elses-app'], null, 'Audience mismatch'];
        yield 'missing audience' => [['aud' => null], null, 'Audience mismatch'];
        yield 'missing subject' => [['sub' => null], null, 'Missing subject'];
        yield 'empty subject' => [['sub' => ''], null, 'Missing subject'];
        yield 'nonce mismatch' => [['nonce' => 'expected'], 'other', 'Nonce mismatch'];
        yield 'nonce claim without request nonce' => [['nonce' => 'expected'], null, 'Nonce required'];
        yield 'request nonce without claim' => [[], 'unexpected', 'Token carries no nonce'];
    }

    /**
     * @param array<string, mixed> $claims
     */
    #[DataProvider('rejections')]
    public function testClaimRejections(array $claims, ?string $rawNonce, string $message): void
    {
        $this->expectException(VerificationException::class);
        $this->expectExceptionMessage($message);

        $this->verifier()->verify($this->profile(), $this->mint($claims), [self::AUDIENCE], $rawNonce);
    }

    /**
     * When the profile requires a nonce (Apple), a token without a nonce
     * claim was never bound to a sign-in ceremony and is replayable for its
     * full lifetime - it must be rejected regardless of the request nonce.
     */
    public function testNonceRequiredProfileRejectsTokenWithoutNonceClaim(): void
    {
        $this->expectException(VerificationException::class);
        $this->expectExceptionMessage('Nonce required');

        $this->verifier()->verify($this->profile(nonceRequired: true), $this->mint([]), [self::AUDIENCE], null);
    }

    public function testNonceRequiredProfileRejectsTokenWithoutNonceClaimEvenWithRequestNonce(): void
    {
        $this->expectException(VerificationException::class);
        $this->expectExceptionMessage('Nonce required');

        $this->verifier()->verify($this->profile(nonceRequired: true), $this->mint([]), [self::AUDIENCE], 'raw-nonce');
    }

    public function testNonceRequiredProfileAcceptsMatchingNonce(): void
    {
        $raw = 'raw-nonce-value';

        $claims = $this->verifier()->verify(
            $this->profile(nonceRequired: true),
            $this->mint(['nonce' => \hash('sha256', $raw)]),
            [self::AUDIENCE],
            $raw,
        );

        $this->assertSame('subject-1', $claims['sub']);
    }

    public function testTamperedPayloadIsRejected(): void
    {
        $parts = \explode('.', $this->mint([]));
        $parts[1] = self::base64UrlEncode(\json_encode(
            \array_merge(self::claims([]), ['sub' => 'attacker'])
        ));

        $this->expectException(VerificationException::class);
        $this->expectExceptionMessage('Invalid signature');

        $this->verifier()->verify($this->profile(), \implode('.', $parts), [self::AUDIENCE], null);
    }

    public function testUnknownKidIsRejected(): void
    {
        $this->expectException(VerificationException::class);
        $this->expectExceptionMessage('Unknown signing key');

        $this->verifier()->verify($this->profile(), $this->mint([], ['kid' => 'rotated-away']), [self::AUDIENCE], null);
    }

    /**
     * Classic algorithm-confusion attack: an HS256 token HMAC-signed with the
     * public key must never verify. The algorithm is pinned, not negotiated.
     */
    public function testHs256ConfusionIsRejected(): void
    {
        $header = self::base64UrlEncode(\json_encode(['alg' => 'HS256', 'kid' => self::KID, 'typ' => 'JWT']));
        $payload = self::base64UrlEncode(\json_encode(self::claims([])));
        $publicPem = \openssl_pkey_get_details(self::$key)['key'];
        $signature = self::base64UrlEncode(\hash_hmac('sha256', $header . '.' . $payload, $publicPem, true));

        $this->expectException(VerificationException::class);
        $this->expectExceptionMessage('Unsupported algorithm');

        $this->verifier()->verify($this->profile(), $header . '.' . $payload . '.' . $signature, [self::AUDIENCE], null);
    }

    public function testAlgNoneIsRejected(): void
    {
        $header = self::base64UrlEncode(\json_encode(['alg' => 'none', 'kid' => self::KID, 'typ' => 'JWT']));
        $payload = self::base64UrlEncode(\json_encode(self::claims([])));

        $this->expectException(VerificationException::class);
        $this->expectExceptionMessage('Unsupported algorithm');

        $this->verifier()->verify($this->profile(), $header . '.' . $payload . '.', [self::AUDIENCE], null);
    }

    public function testMalformedTokenIsRejected(): void
    {
        $this->expectException(VerificationException::class);
        $this->expectExceptionMessage('Malformed token');

        $this->verifier()->verify($this->profile(), 'only.twoparts', [self::AUDIENCE], null);
    }

    public function testMissingKidIsRejected(): void
    {
        $this->expectException(VerificationException::class);
        $this->expectExceptionMessage('Missing key ID');

        $this->verifier()->verify($this->profile(), $this->mint([], ['kid' => null]), [self::AUDIENCE], null);
    }

    private function verifier(): IdTokenVerifier
    {
        $jwks = new Jwks(new Cache(new Memory()), fn (): string => \json_encode(['keys' => [self::$jwk]]));

        return new IdTokenVerifier($jwks);
    }

    private function profile(bool $nonceRequired = false): Profile
    {
        return new Profile('test', [self::ISSUER], 'https://issuer.test/jwks', $nonceRequired);
    }

    /**
     * @param array<string, mixed> $overrides claim overrides; null removes the claim
     */
    private static function claims(array $overrides): array
    {
        $claims = \array_merge([
            'iss' => self::ISSUER,
            'aud' => self::AUDIENCE,
            'sub' => 'subject-1',
            'iat' => \time(),
            'exp' => \time() + 3600,
        ], $overrides);

        return \array_filter($claims, fn ($value) => $value !== null);
    }

    /**
     * @param array<string, mixed> $claimOverrides
     * @param array<string, mixed> $headerOverrides header overrides; null removes the field
     */
    private function mint(array $claimOverrides, array $headerOverrides = []): string
    {
        $header = \array_filter(
            \array_merge(['alg' => 'RS256', 'kid' => self::KID, 'typ' => 'JWT'], $headerOverrides),
            fn ($value) => $value !== null,
        );

        $headerEncoded = self::base64UrlEncode(\json_encode($header));
        $payloadEncoded = self::base64UrlEncode(\json_encode(self::claims($claimOverrides)));

        \openssl_sign($headerEncoded . '.' . $payloadEncoded, $signature, self::$key, OPENSSL_ALGO_SHA256);

        return $headerEncoded . '.' . $payloadEncoded . '.' . self::base64UrlEncode($signature);
    }

    private static function base64UrlEncode(string $data): string
    {
        return \rtrim(\strtr(\base64_encode($data), '+/', '-_'), '=');
    }
}
