<?php

declare(strict_types=1);

namespace Utopia\Auth;

use Utopia\Auth\Enums\Claim;
use Utopia\Auth\Enums\Header;
use Utopia\Auth\Verifiers\VerificationException;

/**
 * Base class for verifying tokens carried as a compact JWS (RFC 7515).
 *
 * The mirror image of {@see Issuer}: it owns everything independent of the
 * signing algorithm — splitting the compact form, base64url/JSON decoding,
 * the "alg" guard and the standard claim checks ("exp"/"nbf"/"iat"/"iss"/"aud")
 * — and delegates the signature check itself to a subclass. The two families
 * live one level down: {@see \Utopia\Auth\Verifiers\Asymmetric} (RS256) and
 * {@see \Utopia\Auth\Verifiers\Symmetric} (HS256).
 *
 * Expectations are passed to the constructor (not fluent setters) and held
 * read-only, so a shared instance is coroutine-safe — its issuer, audience or
 * type cannot be flipped mid-verification. The issuer, audience and type are
 * only checked once supplied. Expiry is enforced by default — "exp" is required
 * and must be in the future — while "nbf"/"iat" are always enforced when
 * present; `$allowExpired` relaxes only the expiry check and `$leeway`
 * tolerates clock skew.
 */
abstract class Verifier
{
    /**
     * Acceptable "aud" values. A token passes when any of these appears in its
     * audience. When null the audience is not checked.
     *
     * @var array<int, string>|null
     */
    protected readonly ?array $audience;

    /**
     * Configuration is immutable: passed once at construction so a shared
     * instance cannot have its expectations flipped mid-verification.
     *
     * @param  string|null  $issuer  Required "iss" claim; not checked when null.
     * @param  string|array<int, string>|null  $audience  Acceptable "aud" value(s); a token passes when any appears in its audience. Not checked when null.
     * @param  string|null  $type  Required "typ" header (e.g. "at+jwt"); not checked when null, so one token kind cannot be accepted in place of another.
     * @param  bool  $allowExpired  When true, skip the "exp" check and its required-presence rule (e.g. an OIDC `id_token_hint`); "nbf"/"iat" are still enforced.
     * @param  int  $leeway  Clock-skew tolerance in seconds for the time-based claims.
     *
     * @throws \InvalidArgumentException When the leeway is negative.
     */
    public function __construct(
        protected readonly ?string $issuer = null,
        string|array|null $audience = null,
        protected readonly ?string $type = null,
        protected readonly bool $allowExpired = false,
        protected readonly int $leeway = 0,
    ) {
        if ($leeway < 0) {
            throw new \InvalidArgumentException('Leeway cannot be negative');
        }

        $this->audience = match (true) {
            $audience === null => null,
            \is_array($audience) => array_values($audience),
            default => [$audience],
        };
    }

    /**
     * The JWS "alg" header the token must carry (e.g. "RS256", "HS256").
     */
    abstract protected function getAlgorithm(): string;

    /**
     * Check the raw (binary) signature against the signing input.
     */
    abstract protected function verifySignature(string $signingInput, string $signature): bool;

    /**
     * Verify a compact JWS and return its claims.
     *
     * The signature is checked first (so claims from a forged token are never
     * trusted), then the "alg" header, then the configured claim expectations.
     *
     * @return array<string, mixed>
     *
     * @throws VerificationException When the token is malformed, the signature
     *                               is invalid, or a claim fails validation.
     */
    public function verify(string $token): array
    {
        $segments = explode('.', $token);
        if (\count($segments) !== 3) {
            throw new VerificationException('Token must have three segments');
        }

        [$encodedHeader, $encodedClaims, $encodedSignature] = $segments;

        $header = $this->decodeSegment($encodedHeader, 'header');
        $claims = $this->decodeSegment($encodedClaims, 'claims');

        $signature = $this->base64UrlDecode($encodedSignature);
        if ($signature === false) {
            throw new VerificationException('Signature is not valid base64url');
        }

        // Reject "none" and any algorithm other than ours before touching the
        // key, closing the classic algorithm-confusion hole.
        if (($header[Header::Algorithm->value] ?? null) !== $this->getAlgorithm()) {
            throw new VerificationException('Unexpected token algorithm');
        }

        if ($this->type !== null && ($header[Header::Type->value] ?? null) !== $this->type) {
            throw new VerificationException('Unexpected token type');
        }

        if (!$this->verifySignature("{$encodedHeader}.{$encodedClaims}", $signature)) {
            throw new VerificationException('Signature verification failed');
        }

        $this->validateClaims($claims);

        return $claims;
    }

    /**
     * Validate the registered claims against the configured expectations.
     *
     * @param  array<string, mixed>  $claims
     *
     * @throws VerificationException
     */
    protected function validateClaims(array $claims): void
    {
        $now = time();

        // "nbf"/"iat" bound when a token *becomes* valid; they are always
        // enforced, so a token that is not valid yet or claims a future
        // issuance is rejected even when expiry is relaxed via allowExpired().
        $nbf = $claims[Claim::NotBefore->value] ?? null;
        if ($nbf !== null) {
            if (!is_numeric($nbf)) {
                throw new VerificationException('Invalid "nbf" claim');
            }
            if ($now + $this->leeway < (int) $nbf) {
                throw new VerificationException('Token is not yet valid');
            }
        }

        $iat = $claims[Claim::IssuedAt->value] ?? null;
        if ($iat !== null) {
            if (!is_numeric($iat)) {
                throw new VerificationException('Invalid "iat" claim');
            }
            if ($now + $this->leeway < (int) $iat) {
                throw new VerificationException('Token was issued in the future');
            }
        }

        // These are bounded-lifetime bearer tokens, so "exp" is required and
        // must be in the future — unless relaxed via $allowExpired.
        if (!$this->allowExpired) {
            $exp = $claims[Claim::Expiration->value] ?? null;
            if ($exp === null) {
                throw new VerificationException('Token is missing the "exp" claim');
            }
            if (!is_numeric($exp)) {
                throw new VerificationException('Invalid "exp" claim');
            }
            if ($now >= (int) $exp + $this->leeway) {
                throw new VerificationException('Token has expired');
            }
        }

        if ($this->issuer !== null && ($claims[Claim::Issuer->value] ?? null) !== $this->issuer) {
            throw new VerificationException('Unexpected token issuer');
        }

        if ($this->audience !== null && !$this->audienceMatches($claims[Claim::Audience->value] ?? null)) {
            throw new VerificationException('Unexpected token audience');
        }
    }

    /**
     * Whether the token's "aud" claim (a string or list per RFC 7519 §4.1.3)
     * contains any of the configured acceptable audiences.
     */
    private function audienceMatches(mixed $aud): bool
    {
        $tokenAudiences = \is_array($aud) ? $aud : [$aud];

        foreach ($this->audience ?? [] as $expected) {
            if (\in_array($expected, $tokenAudiences, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Base64url-decode then JSON-decode a token segment into an object.
     *
     * @return array<string, mixed>
     *
     * @throws VerificationException When the segment is not base64url, not JSON,
     *                               or not a JSON object.
     */
    private function decodeSegment(string $segment, string $name): array
    {
        $label = ucfirst($name);

        $decoded = $this->base64UrlDecode($segment);
        if ($decoded === false) {
            throw new VerificationException("{$label} is not valid base64url");
        }

        try {
            $data = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new VerificationException("{$label} is not valid JSON");
        }

        // json_decode(..., true) maps both JSON objects and JSON arrays to PHP
        // arrays; a populated list means the segment was a JSON array, which is
        // not a valid JWT header/claims object.
        if (!\is_array($data) || (array_is_list($data) && $data !== [])) {
            throw new VerificationException("{$label} must be a JSON object");
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * Base64url-decode without requiring padding (RFC 7515 §2). Returns false
     * on input outside the base64url alphabet.
     */
    protected function base64UrlDecode(string $value): string|false
    {
        return base64_decode(strtr($value, '-_', '+/'), true);
    }
}
