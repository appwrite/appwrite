<?php

namespace Utopia\Auth\Verifiers;

use Utopia\Auth\Verifier;

/**
 * Verifies tokens signed asymmetrically with RS256.
 *
 * Holds only the PEM-encoded RSA public key, so it can validate tokens minted
 * by {@see \Utopia\Auth\Issuers\Asymmetric} (or any RS256 issuer advertising
 * the matching key on a JWKS endpoint) without access to the private key.
 */
class Asymmetric extends Verifier
{
    /**
     * @param  string  $publicKey  PEM-encoded RSA public key used to verify the signature.
     * @param  string|null  $issuer  Required "iss" claim; not checked when null.
     * @param  string|array<int, string>|null  $audience  Acceptable "aud" value(s); not checked when null.
     * @param  string|null  $type  Required "typ" header (e.g. "at+jwt"); not checked when null.
     * @param  bool  $allowExpired  Skip the "exp" check when true; "nbf"/"iat" stay enforced.
     * @param  int  $leeway  Clock-skew tolerance in seconds.
     *
     * @throws \Exception When the public key is missing.
     */
    public function __construct(
        protected readonly string $publicKey,
        ?string $issuer = null,
        string|array|null $audience = null,
        ?string $type = null,
        bool $allowExpired = false,
        int $leeway = 0,
    ) {
        if ($publicKey === '' || $publicKey === '0') {
            throw new \Exception('A public key is required');
        }

        parent::__construct($issuer, $audience, $type, $allowExpired, $leeway);
    }

    /**
     * Derive the JWS "kid" deterministically from the RSA modulus, matching
     * {@see \Utopia\Auth\Issuers\Asymmetric::getKeyId()} so issuer and verifier
     * agree on the key id for the same key.
     *
     * @throws VerificationException When the public key cannot be parsed.
     */
    public function getKeyId(): string
    {
        return hash('sha256', $this->getModulus());
    }

    protected function getAlgorithm(): string
    {
        return 'RS256';
    }

    /**
     * @throws VerificationException When the public key cannot be parsed.
     */
    protected function verifySignature(string $signingInput, string $signature): bool
    {
        $publicKey = openssl_pkey_get_public($this->publicKey);
        if ($publicKey === false) {
            throw new VerificationException('Unable to parse the public key');
        }

        $details = openssl_pkey_get_details($publicKey);
        if ($details === false || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
            throw new VerificationException('Public key is not an RSA key');
        }

        return openssl_verify($signingInput, $signature, $publicKey, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * Read the raw RSA modulus (the "n" parameter) from the public key.
     *
     * @throws VerificationException When the public key cannot be parsed.
     */
    protected function getModulus(): string
    {
        $publicKey = openssl_pkey_get_public($this->publicKey);
        if ($publicKey === false) {
            throw new VerificationException('Unable to parse the public key');
        }

        $details = openssl_pkey_get_details($publicKey);
        if ($details === false || !isset($details['rsa']['n'])) {
            throw new VerificationException('Public key is not an RSA key');
        }

        return $details['rsa']['n'];
    }
}
