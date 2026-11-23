<?php

namespace Utopia\Auth\Issuers;

use Utopia\Auth\Enums\Header;
use Utopia\Auth\Issuer;

/**
 * Base class for tokens signed asymmetrically with RS256.
 *
 * The signing key is an RSA keypair whose public half can be advertised on a
 * JWKS endpoint, so any third party (a resource server, a client) can verify
 * the issued tokens without sharing a secret. {@see Asymmetric\IdToken} and
 * {@see Asymmetric\AccessToken} build on this.
 */
abstract class Asymmetric extends Issuer
{
    /**
     * @param  string  $privateKey  PEM-encoded RSA private key used to sign tokens, generate using {@see generateKeyPair()}.
     * @param  string  $publicKey  PEM-encoded RSA public key matching the private key, generate using {@see generateKeyPair()}.
     * @param  string  $issuer  The "iss" claim value.
     * @param  string|null  $keyId  Optional "kid" header; derived from the public key when null.
     *
     * @throws \Exception When a key or the issuer is missing.
     */
    public function __construct(
        protected readonly string $privateKey,
        protected readonly string $publicKey,
        string $issuer,
        protected ?string $keyId = null,
    ) {
        parent::__construct($issuer);

        if ($privateKey === '' || $privateKey === '0' || ($publicKey === '' || $publicKey === '0')) {
            throw new \Exception('Both a private and a public key are required');
        }
    }

    /**
     * Generate a fresh RSA keypair suitable for signing tokens with RS256.
     *
     * Returns a tuple of PEM-encoded keys that can be passed straight to the
     * constructor: [$privateKey, $publicKey].
     *
     * @return array{0: string, 1: string}
     *
     * @throws \Exception When key generation fails.
     */
    public static function generateKeyPair(int $bits = 2048): array
    {
        $resource = openssl_pkey_new([
            'private_key_bits' => $bits,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($resource === false) {
            throw new \Exception('Unable to generate an RSA key pair');
        }

        return [
            self::exportPrivateKey($resource),
            self::exportPublicKey($resource),
        ];
    }

    /**
     * Export the PEM-encoded private key from an OpenSSL key resource.
     *
     * @throws \Exception When the key cannot be exported.
     */
    private static function exportPrivateKey(\OpenSSLAsymmetricKey $resource): string
    {
        $privateKey = '';
        if (!openssl_pkey_export($resource, $privateKey)) {
            throw new \Exception('Unable to export the private key');
        }

        return $privateKey;
    }

    /**
     * Export the PEM-encoded public key from an OpenSSL key resource.
     *
     * @throws \Exception When the public key cannot be derived.
     */
    private static function exportPublicKey(\OpenSSLAsymmetricKey $resource): string
    {
        $details = openssl_pkey_get_details($resource);
        if ($details === false || !isset($details['key'])) {
            throw new \Exception('Unable to export the public key');
        }

        return $details['key'];
    }

    /**
     * Get the JWS "kid" header. When none was supplied it is derived
     * deterministically from the public key's RSA modulus, so the same key
     * always yields the same id.
     *
     * @throws \Exception When the public key cannot be parsed.
     */
    public function getKeyId(): string
    {
        return $this->keyId ??= $this->deriveKeyId($this->getModulus());
    }

    /**
     * Build the public key as a JWK (RFC 7517) suitable for publishing on a
     * JWKS endpoint so clients can verify the issued tokens.
     *
     * @return array<string, string>
     *
     * @throws \Exception When the public key cannot be parsed.
     */
    public function getPublicJwk(): array
    {
        $publicKey = openssl_pkey_get_public($this->publicKey);
        if ($publicKey === false) {
            throw new \Exception('Unable to parse the public key');
        }

        $details = openssl_pkey_get_details($publicKey);
        if ($details === false || !isset($details['rsa'])) {
            throw new \Exception('Public key is not an RSA key');
        }

        return [
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            // Reuse the modulus already in $details rather than re-parsing
            // the key via getKeyId() -> getModulus().
            'kid' => $this->keyId ??= $this->deriveKeyId($details['rsa']['n']),
            'n' => $this->base64UrlEncode($details['rsa']['n']),
            'e' => $this->base64UrlEncode($details['rsa']['e']),
        ];
    }

    protected function getAlgorithm(): string
    {
        return 'RS256';
    }

    /**
     * @return array<string, mixed>
     *
     * @throws \Exception When the public key cannot be parsed.
     */
    #[\Override]
    protected function getHeaders(): array
    {
        return [Header::KeyId->value => $this->getKeyId()];
    }

    /**
     * @throws \Exception When the private key cannot be parsed or signing fails.
     */
    protected function signInput(string $signingInput): string
    {
        $privateKey = openssl_pkey_get_private($this->privateKey);
        if ($privateKey === false) {
            throw new \Exception('Unable to parse the private key');
        }

        $signature = '';
        if (!openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new \Exception('Unable to sign the token');
        }

        return $signature;
    }

    /**
     * Derive a deterministic key id from the RSA modulus, so the same key
     * always yields the same "kid".
     */
    private function deriveKeyId(string $modulus): string
    {
        return hash('sha256', $modulus);
    }

    /**
     * Read the raw RSA modulus (the "n" parameter) from the public key.
     *
     * @throws \Exception When the public key cannot be parsed.
     */
    protected function getModulus(): string
    {
        $publicKey = openssl_pkey_get_public($this->publicKey);
        if ($publicKey === false) {
            throw new \Exception('Unable to parse the public key');
        }

        $details = openssl_pkey_get_details($publicKey);
        if ($details === false || !isset($details['rsa']['n'])) {
            throw new \Exception('Public key is not an RSA key');
        }

        return $details['rsa']['n'];
    }
}
