<?php

namespace Appwrite\Auth\OIDC;

/**
 * Verifies an OpenID Connect ID token against a provider Profile.
 *
 * The algorithm is pinned to RS256 — the token header is never trusted to
 * choose it — and the signature is checked before any claim is read.
 */
class IdTokenVerifier
{
    public const CLOCK_SKEW = 60; // seconds

    public function __construct(private Jwks $jwks)
    {
    }

    /**
     * @param string[] $allowedAudiences client IDs accepted as the `aud` claim
     * @param ?string $rawNonce raw nonce from the request; the claim may carry it verbatim (Google) or as its SHA-256 hex hash (Apple)
     * @return array<string, mixed> the verified claims
     * @throws VerificationException
     * @throws JwksException
     */
    public function verify(Profile $profile, string $idToken, array $allowedAudiences, ?string $rawNonce): array
    {
        $parts = \explode('.', $idToken);
        if (\count($parts) !== 3) {
            throw new VerificationException('Malformed token');
        }
        [$headerEncoded, $payloadEncoded, $signatureEncoded] = $parts;

        $header = $this->decodeJson($headerEncoded);
        if (($header['alg'] ?? null) !== 'RS256') {
            throw new VerificationException('Unsupported algorithm');
        }

        $kid = $header['kid'] ?? null;
        if (!\is_string($kid) || $kid === '') {
            throw new VerificationException('Missing key ID');
        }

        $jwk = $this->jwks->getKey($profile->jwksUrl, $kid);
        if ($jwk === null) {
            throw new VerificationException('Unknown signing key');
        }

        $publicKey = \openssl_pkey_get_public(JwkConverter::rsaToPem($jwk['n'], $jwk['e']));
        if ($publicKey === false) {
            throw new VerificationException('Invalid signing key');
        }

        $signature = $this->decodeBase64Url($signatureEncoded);
        if ($signature === false || $signature === '') {
            throw new VerificationException('Malformed signature');
        }
        if (\openssl_verify($headerEncoded . '.' . $payloadEncoded, $signature, $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
            throw new VerificationException('Invalid signature');
        }

        $claims = $this->decodeJson($payloadEncoded);

        if (!\in_array($claims['iss'] ?? null, $profile->issuers, true)) {
            throw new VerificationException('Invalid issuer');
        }

        $audiences = $claims['aud'] ?? [];
        $audiences = \is_array($audiences) ? $audiences : [$audiences];
        if (empty(\array_intersect($audiences, $allowedAudiences))) {
            throw new VerificationException('Audience mismatch. Add the token\'s client ID to the provider configuration.');
        }

        $now = \time();

        $exp = $claims['exp'] ?? null;
        if (!\is_numeric($exp) || (int) $exp <= $now - self::CLOCK_SKEW) {
            throw new VerificationException('Token expired');
        }
        foreach (['iat', 'nbf'] as $claim) {
            if (isset($claims[$claim]) && (!\is_numeric($claims[$claim]) || (int) $claims[$claim] >= $now + self::CLOCK_SKEW)) {
                throw new VerificationException('Token not yet valid');
            }
        }

        $nonce = $claims['nonce'] ?? null;
        if (\is_string($nonce) && $nonce !== '') {
            if ($rawNonce === null || $rawNonce === '') {
                throw new VerificationException('Nonce required');
            }
            if (!\hash_equals($nonce, $rawNonce) && !\hash_equals($nonce, \hash('sha256', $rawNonce))) {
                throw new VerificationException('Nonce mismatch');
            }
        } elseif ($rawNonce !== null && $rawNonce !== '') {
            throw new VerificationException('Token carries no nonce');
        }

        $sub = $claims['sub'] ?? null;
        if (!\is_string($sub) || $sub === '') {
            throw new VerificationException('Missing subject');
        }

        return $claims;
    }

    /**
     * @return array<string, mixed>
     * @throws VerificationException
     */
    private function decodeJson(string $encoded): array
    {
        $decoded = $this->decodeBase64Url($encoded);
        if ($decoded === false) {
            throw new VerificationException('Malformed token');
        }

        $data = \json_decode($decoded, true);
        if (!\is_array($data)) {
            throw new VerificationException('Malformed token');
        }

        return $data;
    }

    private function decodeBase64Url(string $data): string|false
    {
        $remainder = \strlen($data) % 4;
        if ($remainder > 0) {
            $data .= \str_repeat('=', 4 - $remainder);
        }

        return \base64_decode(\strtr($data, '-_', '+/'), true);
    }
}
