<?php

declare(strict_types=1);

namespace Utopia\Auth\Issuers\Symmetric;

use Utopia\Auth\Enums\Claim;
use Utopia\Auth\Issuers\Symmetric;

/**
 * Issues an OAuth2 refresh token as an HS256 JWT.
 *
 * A refresh token is presented only back to the issuing authorization
 * server's token endpoint — never to a resource server or client — so it is
 * signed symmetrically (HS256) with a server-held secret rather than the
 * asymmetric key advertised on the JWKS endpoint (one concrete
 * {@see Symmetric}).
 *
 * The "jti" uniquely identifies the token; bind rotation and reuse-detection
 * state to it out of band (e.g. a database row) so a replayed or rotated
 * token can be rejected.
 */
class RefreshToken extends Symmetric
{
    /**
     * {@inheritdoc}
     */
    protected function getType(): string
    {
        return 'JWT';
    }

    /**
     * Build a signed HS256 refresh token.
     *
     * @param  string  $subject  The "sub" claim (the resource owner / user).
     * @param  string  $audience  The "aud" claim (the authorization server / token endpoint).
     * @param  string  $clientId  The "client_id" claim (the client the token was issued to).
     * @param  int  $duration  Lifetime of the token in seconds (used for "exp").
     * @param  array<string>  $scopes  Granted scopes; joined into the space-delimited "scope" claim when non-empty.
     * @param  string|null  $jti  The "jti" claim; a random identifier is generated when null.
     * @param  array<string, mixed>  $claims  Additional claims to merge into the payload.
     *
     * @throws \Exception When signing fails.
     */
    public function issue(
        string $subject,
        string $audience,
        string $clientId,
        int $duration,
        array $scopes = [],
        ?string $jti = null,
        array $claims = [],
    ): string {
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
        ];

        if ($scopes !== []) {
            $claims[Claim::Scope->value] = implode(' ', $scopes);
        }

        return $this->sign($claims);
    }
}
