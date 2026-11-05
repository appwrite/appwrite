<?php

declare(strict_types=1);

namespace Utopia\Auth\Issuers\Asymmetric;

use Utopia\Auth\Enums\Claim;
use Utopia\Auth\Issuers\Asymmetric;

/**
 * Issues an OAuth2 access token as an RFC 9068 "JWT Profile for OAuth 2.0
 * Access Tokens" JWT.
 *
 * One concrete {@see Asymmetric}: it inherits the RSA key handling and
 * RS256 signing — the same key advertised on the JWKS endpoint — so resource
 * servers can verify these tokens with any off-the-shelf JWT library. The
 * "at+jwt" header type (RFC 9068 §2.1) lets verifiers tell access tokens
 * apart from id_tokens at the header level.
 */
class AccessToken extends Asymmetric
{
    /**
     * {@inheritdoc}
     *
     * RFC 9068 §2.1: the explicit access-token JWT media type.
     */
    protected function getType(): string
    {
        return 'at+jwt';
    }

    /**
     * Build a signed RS256 access token per RFC 9068.
     *
     * The audience identifies the resource server the token is for and, per
     * RFC 9068 §3, is distinct from the issuer (which identifies the
     * authorization server, supplied to the constructor).
     *
     * @param  string  $subject  The "sub" claim (the resource owner / user).
     * @param  array<int, string>  $audience  The "aud" claim (the resource server identifiers).
     * @param  string  $clientId  The "client_id" claim (the client the token was issued to).
     * @param  int  $authTime  Time the end-user authenticated ("auth_time"), as a Unix timestamp.
     * @param  int  $duration  Lifetime of the token in seconds (used for "exp").
     * @param  array<string>  $scopes  Granted scopes; joined into the space-delimited "scope" claim when non-empty.
     * @param  string|null  $jti  The "jti" claim; a random identifier is generated when null.
     * @param  array<string, mixed>  $claims  Additional claims to merge into the payload.
     *
     * @throws \Exception When signing fails.
     */
    public function issue(
        string $subject,
        array $audience,
        string $clientId,
        int $authTime,
        int $duration,
        array $scopes = [],
        ?string $jti = null,
        array $claims = [],
    ): string {
        if ($audience === []) {
            throw new \InvalidArgumentException('audience must contain at least one resource server identifier.');
        }

        if ($audience !== array_values($audience)) {
            throw new \InvalidArgumentException('audience must be a list of resource server identifiers.');
        }

        foreach ($audience as $identifier) {
            if ($identifier === '') {
                throw new \InvalidArgumentException('audience must contain non-empty resource server identifiers.');
            }
        }

        $now = time();

        // "scope" is issuer-controlled; drop any caller-supplied value so it
        // cannot be injected through $claims when $scopes is empty.
        unset($claims[Claim::Scope->value]);

        $claims = [
            ...$claims,
            Claim::Issuer->value => $this->issuer,
            Claim::Audience->value => $audience,
            Claim::Subject->value => $subject,
            Claim::ClientId->value => $clientId,
            Claim::Expiration->value => $now + $duration,
            Claim::IssuedAt->value => $now,
            Claim::JwtId->value => $jti ?? $this->generateJti(),
            Claim::AuthTime->value => $authTime,
        ];

        if ($scopes !== []) {
            $claims[Claim::Scope->value] = implode(' ', $scopes);
        }

        return $this->sign($claims);
    }
}
