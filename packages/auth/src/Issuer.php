<?php

namespace Utopia\Auth;

use Utopia\Auth\Enums\Header;

/**
 * Base class for tokens issued as a compact JWS (RFC 7515).
 *
 * It owns everything that is independent of the signing algorithm — the
 * issuer identity, the "jti" generation, base64url encoding and the
 * assembly of the header/payload/signature triple — and delegates the
 * algorithm itself ("alg" header + signature) to a subclass. The two signing
 * families live one level down: {@see \Utopia\Auth\Issuers\Asymmetric}
 * (RS256) and {@see \Utopia\Auth\Issuers\Symmetric} (HS256).
 */
abstract class Issuer
{
    /**
     * @param  string  $issuer  The token issuer (the "iss" claim). For OAuth2/OIDC
     *                          this is the URL of the authorization server,
     *                          e.g. "https://example.com/v1/oauth2/<id>".
     *
     * @throws \Exception When the issuer is missing.
     */
    public function __construct(protected readonly string $issuer)
    {
        if ($issuer === '' || $issuer === '0') {
            throw new \Exception('An issuer is required');
        }
    }

    /**
     * The JWS "typ" header value for tokens produced by this issuer
     * (e.g. "JWT" for an OIDC id_token, "at+jwt" for an RFC 9068 access token).
     */
    abstract protected function getType(): string;

    /**
     * The JWS "alg" header value (e.g. "RS256", "HS256").
     */
    abstract protected function getAlgorithm(): string;

    /**
     * Produce the raw (binary) signature for the given signing input.
     */
    abstract protected function signInput(string $signingInput): string;

    /**
     * Extra header fields to merge in on top of "typ" and "alg"
     * (e.g. a "kid"). Empty by default.
     *
     * @return array<string, mixed>
     */
    protected function getHeaders(): array
    {
        return [];
    }

    /**
     * Encode a set of claims into a signed compact JWS. The header is built
     * from {@see getType()}, {@see getAlgorithm()} and {@see getHeaders()};
     * the signature is delegated to {@see signInput()}.
     *
     * @param  array<string, mixed>  $claims
     *
     * @throws \JsonException When the header or claims cannot be JSON-encoded.
     * @throws \Exception When signing fails.
     */
    protected function sign(array $claims): string
    {
        $header = [
            Header::Type->value => $this->getType(),
            Header::Algorithm->value => $this->getAlgorithm(),
            ...$this->getHeaders(),
        ];

        $signingInput = $this->base64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR))
            . '.'
            . $this->base64UrlEncode(json_encode($claims, JSON_THROW_ON_ERROR));

        return $signingInput . '.' . $this->base64UrlEncode($this->signInput($signingInput));
    }

    /**
     * Generate a unique token identifier suitable for the "jti" claim
     * (RFC 7519 §4.1.7) as a random hex string.
     *
     * @param  int<1, max>  $bytes
     *
     * @throws \Exception When sufficient randomness is unavailable.
     */
    protected function generateJti(int $bytes = 16): string
    {
        return bin2hex(random_bytes($bytes));
    }

    /**
     * Base64url-encode without padding (RFC 7515 §2).
     */
    protected function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
