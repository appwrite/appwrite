<?php

namespace Appwrite\Auth\OIDC;

use Ahc\Jwt\JWT;
use Ahc\Jwt\JWTException;
use Appwrite\Extend\Exception;
use Utopia\Database\Document;

/**
 * Verifies an OpenID Connect ID token against the provider's `idToken`
 * profile from the oAuthProviders config.
 *
 * The JWT layer (signature, expiry, not-before, age) is the same library that
 * signs Appwrite's own JWTs. It verifies with the pinned RS256 whatever the
 * token header claims, which defeats algorithm confusion. The OpenID claims
 * (issuer, audience, nonce, subject) and a future `iat` are checked here.
 */
class IdTokenVerifier
{
    public const CLOCK_SKEW = 60; // seconds

    /**
     * Upper bound on token age since `iat`. Providers set `exp` minutes after
     * `iat`, so expiry governs; this only stops a token with no `exp` from
     * living forever.
     */
    private const MAX_AGE = 86400;

    public function __construct(private Jwks $jwks)
    {
    }

    /**
     * @param Document $profile `issuers`, `jwksUrl` and `nonceRequired` for the provider
     * @param string[] $allowedAudiences client IDs accepted as the `aud` claim
     * @param ?string $rawNonce raw nonce from the request; the claim may carry it verbatim (Google) or as its SHA-256 hex hash (Apple). Ignored when the token carries no nonce claim and the profile does not require one
     * @return array<string, mixed> the verified claims
     * @throws Exception
     */
    public function verify(Document $profile, string $idToken, array $allowedAudiences, ?string $rawNonce): array
    {
        $parts = \explode('.', $idToken);
        if (\count($parts) !== 3) {
            throw new Exception(Exception::USER_OAUTH2_TOKEN_INVALID, 'Malformed token');
        }

        // The signing key is looked up by kid before the token can be verified
        $header = \json_decode(\base64_decode(\strtr($parts[0], '-_', '+/')), true);
        if (!\is_array($header)) {
            throw new Exception(Exception::USER_OAUTH2_TOKEN_INVALID, 'Malformed token');
        }

        $kid = $header['kid'] ?? null;
        if (!\is_string($kid) || $kid === '') {
            throw new Exception(Exception::USER_OAUTH2_TOKEN_INVALID, 'Missing key ID');
        }

        $pem = $this->jwks->getKey($profile->getAttribute('jwksUrl', ''), $kid);
        if ($pem === null) {
            throw new Exception(Exception::USER_OAUTH2_TOKEN_INVALID, 'Unknown signing key');
        }

        $publicKey = \openssl_pkey_get_public($pem);
        if ($publicKey === false) {
            throw new Exception(Exception::USER_OAUTH2_TOKEN_INVALID, 'Invalid signing key');
        }

        try {
            $claims = (new JWT($publicKey, 'RS256', self::MAX_AGE, self::CLOCK_SKEW))
                ->registerKeys([$kid => $publicKey])
                ->decode($idToken);
        } catch (JWTException $error) {
            throw new Exception(Exception::USER_OAUTH2_TOKEN_INVALID, $error->getMessage());
        }

        if (!\is_numeric($claims['exp'] ?? null)) {
            throw new Exception(Exception::USER_OAUTH2_TOKEN_INVALID, 'Token expired');
        }

        // The JWT layer caps how old `iat` may be but accepts one in the future,
        // which would let a correctly signed token in before it is valid.
        if (\is_numeric($claims['iat'] ?? null) && $claims['iat'] > \time() + self::CLOCK_SKEW) {
            throw new Exception(Exception::USER_OAUTH2_TOKEN_INVALID, 'Token issued in the future');
        }

        if (!\in_array($claims['iss'] ?? null, $profile->getAttribute('issuers', []), true)) {
            throw new Exception(Exception::USER_OAUTH2_TOKEN_INVALID, 'Invalid issuer');
        }

        $audiences = $claims['aud'] ?? [];
        $audiences = \is_array($audiences) ? $audiences : [$audiences];
        if (empty(\array_intersect($audiences, $allowedAudiences))) {
            throw new Exception(Exception::USER_OAUTH2_TOKEN_INVALID, 'Audience mismatch. Add the token\'s client ID to the provider configuration.');
        }

        $nonce = $claims['nonce'] ?? null;
        $hasNonceClaim = \is_string($nonce) && $nonce !== '';
        if ($profile->getAttribute('nonceRequired', false) && !$hasNonceClaim) {
            throw new Exception(Exception::USER_OAUTH2_TOKEN_INVALID, 'Nonce required');
        }
        // A nonce claim must match the request nonce. A token issued without one
        // by a provider that does not require it is accepted whether or not the
        // request carries a nonce: some Google SDKs (Sign-In on iOS) cannot
        // attach one, and refusing would gain nothing, since a nonce-less token
        // can always be presented without a request nonce anyway.
        if ($hasNonceClaim) {
            if ($rawNonce === null || $rawNonce === '') {
                throw new Exception(Exception::USER_OAUTH2_TOKEN_INVALID, 'Nonce required');
            }
            if (!\hash_equals($nonce, $rawNonce) && !\hash_equals($nonce, \hash('sha256', $rawNonce))) {
                throw new Exception(Exception::USER_OAUTH2_TOKEN_INVALID, 'Nonce mismatch');
            }
        }

        $sub = $claims['sub'] ?? null;
        if (!\is_string($sub) || $sub === '') {
            throw new Exception(Exception::USER_OAUTH2_TOKEN_INVALID, 'Missing subject');
        }

        return $claims;
    }
}
