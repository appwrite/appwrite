<?php

declare(strict_types=1);

namespace Utopia\Tests\Auth\Verifiers;

use PHPUnit\Framework\TestCase;
use Utopia\Auth\Issuers\Asymmetric\AccessToken;
use Utopia\Auth\Issuers\Symmetric\RefreshToken;
use Utopia\Auth\Verifiers\Asymmetric;
use Utopia\Auth\Verifiers\VerificationException;

final class AsymmetricTest extends TestCase
{
    protected string $privateKey;

    protected string $publicKey;

    protected AccessToken $issuer;

    protected Asymmetric $verifier;

    protected string $iss = 'https://example.com/v1/oauth2/test';

    protected function setUp(): void
    {
        [$this->privateKey, $this->publicKey] = AccessToken::generateKeyPair();
        $this->issuer = new AccessToken($this->privateKey, $this->publicKey, $this->iss);
        $this->verifier = new Asymmetric($this->publicKey);
    }

    /**
     * Hand-sign an RS256 JWS so tests can craft tokens the issuers never
     * produce (e.g. without "exp", or with a non-object segment).
     *
     * @param  array<array-key, mixed>  $claims
     * @param  array<string, mixed>  $header
     */
    private function signRs256(array $claims, array $header = ['typ' => 'at+jwt', 'alg' => 'RS256']): string
    {
        $encode = fn(mixed $part): string => rtrim(strtr(base64_encode((string) json_encode($part)), '+/', '-_'), '=');

        $signingInput = $encode($header) . '.' . $encode($claims);
        openssl_sign($signingInput, $signature, $this->privateKey, OPENSSL_ALGO_SHA256);

        return $signingInput . '.' . rtrim(strtr(base64_encode((string) $signature), '+/', '-_'), '=');
    }

    public function testVerifiesIssuedToken(): void
    {
        $token = $this->issuer->issue('user-123', ['https://api.example.com'], 'client-abc', 1000, 3600, ['read', 'write']);
        $claims = $this->verifier->verify($token);

        $this->assertEquals('user-123', $claims['sub']);
        $this->assertEquals($this->iss, $claims['iss']);
        $this->assertEquals(['https://api.example.com'], $claims['aud']);
        $this->assertEquals('read write', $claims['scope']);
    }

    public function testKeyIdMatchesIssuer(): void
    {
        // Issuer and verifier must agree on the "kid" for the same key.
        $this->assertSame($this->issuer->getKeyId(), $this->verifier->getKeyId());
    }

    public function testIssuerCheckPasses(): void
    {
        $token = $this->issuer->issue('u', ['aud'], 'c', 1000, 3600);
        $claims = (new Asymmetric($this->publicKey, issuer: $this->iss))->verify($token);

        $this->assertEquals($this->iss, $claims['iss']);
    }

    public function testIssuerMismatchRejected(): void
    {
        $token = $this->issuer->issue('u', ['aud'], 'c', 1000, 3600);

        $this->expectException(VerificationException::class);
        $this->expectExceptionMessage('Unexpected token issuer');
        (new Asymmetric($this->publicKey, issuer: 'https://evil.example.com'))->verify($token);
    }

    public function testAudienceMembershipPasses(): void
    {
        $token = $this->issuer->issue('u', ['https://a.example.com', 'https://b.example.com'], 'c', 1000, 3600);
        $claims = (new Asymmetric($this->publicKey, audience: 'https://b.example.com'))->verify($token);

        $this->assertContains('https://b.example.com', $claims['aud']);
    }

    public function testAudienceMismatchRejected(): void
    {
        $token = $this->issuer->issue('u', ['https://a.example.com'], 'c', 1000, 3600);

        $this->expectException(VerificationException::class);
        $this->expectExceptionMessage('Unexpected token audience');
        (new Asymmetric($this->publicKey, audience: 'https://other.example.com'))->verify($token);
    }

    public function testExpiredTokenRejected(): void
    {
        // A negative duration puts "exp" in the past.
        $token = $this->issuer->issue('u', ['aud'], 'c', 1000, -3600);

        $this->expectException(VerificationException::class);
        $this->expectExceptionMessage('Token has expired');
        $this->verifier->verify($token);
    }

    public function testExpiredTokenAcceptedWhenAllowed(): void
    {
        $token = $this->issuer->issue('u', ['aud'], 'c', 1000, -3600);
        $claims = (new Asymmetric($this->publicKey, allowExpired: true))->verify($token);

        $this->assertEquals('u', $claims['sub']);
    }

    public function testTamperedSignatureRejected(): void
    {
        $token = $this->issuer->issue('u', ['aud'], 'c', 1000, 3600);
        $parts = explode('.', $token);
        $sig = $parts[2];
        // Flip the first base64url char: it encodes the high bits of the first
        // signature byte and is always significant, so the corruption is
        // deterministic (unlike the last char, whose low bits are padding).
        $first = $sig[0];
        $parts[2] = ($first === 'A' ? 'B' : 'A') . substr($sig, 1);

        $this->expectException(VerificationException::class);
        $this->expectExceptionMessage('Signature verification failed');
        $this->verifier->verify(implode('.', $parts));
    }

    public function testTamperedClaimsRejected(): void
    {
        $token = $this->issuer->issue('u', ['aud'], 'c', 1000, 3600);
        $parts = explode('.', $token);
        // Swap the payload for a forged one while keeping the original signature.
        $parts[1] = rtrim(strtr(base64_encode((string) json_encode(['sub' => 'attacker'])), '+/', '-_'), '=');

        $this->expectException(VerificationException::class);
        $this->expectExceptionMessage('Signature verification failed');
        $this->verifier->verify(implode('.', $parts));
    }

    public function testWrongKeyRejected(): void
    {
        [, $otherPublic] = AccessToken::generateKeyPair();
        $token = $this->issuer->issue('u', ['aud'], 'c', 1000, 3600);

        $this->expectException(VerificationException::class);
        $this->expectExceptionMessage('Signature verification failed');
        (new Asymmetric($otherPublic))->verify($token);
    }

    public function testAlgorithmMismatchRejected(): void
    {
        // An HS256 token must never be accepted by the RS256 verifier.
        $hsToken = (new RefreshToken('a-shared-secret', $this->iss))->issue('u', 'aud', 'c', 3600);

        $this->expectException(VerificationException::class);
        $this->expectExceptionMessage('Unexpected token algorithm');
        $this->verifier->verify($hsToken);
    }

    public function testMalformedTokenRejected(): void
    {
        $this->expectException(VerificationException::class);
        $this->expectExceptionMessage('Token must have three segments');
        $this->verifier->verify('not-a-jwt');
    }

    public function testMissingExpirationRejected(): void
    {
        // A signed token with no "exp" must not verify forever.
        $token = $this->signRs256(['sub' => 'u']);

        $this->expectException(VerificationException::class);
        $this->expectExceptionMessage('Token is missing the "exp" claim');
        $this->verifier->verify($token);
    }

    public function testNotYetValidRejectedEvenWhenExpiredAllowed(): void
    {
        // allowExpired relaxes only "exp"; a future "nbf" is still rejected.
        $token = $this->signRs256(['exp' => time() + 3600, 'nbf' => time() + 3600]);

        $this->expectException(VerificationException::class);
        $this->expectExceptionMessage('Token is not yet valid');
        (new Asymmetric($this->publicKey, allowExpired: true))->verify($token);
    }

    public function testFutureIssuedAtRejected(): void
    {
        $token = $this->signRs256(['exp' => time() + 3600, 'iat' => time() + 3600]);

        $this->expectException(VerificationException::class);
        $this->expectExceptionMessage('Token was issued in the future');
        $this->verifier->verify($token);
    }

    public function testTypeMismatchRejected(): void
    {
        // The issuer mints "at+jwt"; pinning a different type must reject it.
        $token = $this->issuer->issue('u', ['aud'], 'c', 1000, 3600);

        $this->expectException(VerificationException::class);
        $this->expectExceptionMessage('Unexpected token type');
        (new Asymmetric($this->publicKey, type: 'JWT'))->verify($token);
    }

    public function testTypeMatchAccepted(): void
    {
        $token = $this->issuer->issue('u', ['aud'], 'c', 1000, 3600);
        $claims = (new Asymmetric($this->publicKey, type: 'at+jwt'))->verify($token);

        $this->assertSame('u', $claims['sub']);
    }

    public function testNonObjectClaimsRejected(): void
    {
        // A JSON array as the claims segment is not a valid JWT payload.
        $token = $this->signRs256([1, 2, 3]);

        $this->expectException(VerificationException::class);
        $this->expectExceptionMessage('Claims must be a JSON object');
        $this->verifier->verify($token);
    }
}
