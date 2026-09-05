<?php

namespace Appwrite\Auth\OIDC;

use Utopia\Cache\Cache;

/**
 * Signing key for the mock OAuth2 provider's ID tokens, used by e2e tests.
 *
 * The key pair is generated on first use and shared between workers through
 * the cache, so no private key material lives in the repository. The key ID is
 * derived from the modulus, so regenerating the key yields a new `kid` and
 * verifiers holding a stale JWKS refresh instead of failing to verify.
 */
class MockKeys
{
    private const CACHE_KEY = 'oidc-mock-signing-key';
    private const TTL = 86400;

    /**
     * @return array<string, string> the public key as an RSA JWK
     */
    public static function jwk(Cache $cache): array
    {
        $details = \openssl_pkey_get_details(self::key($cache));

        return [
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => self::kid($details),
            'n' => self::encode($details['rsa']['n']),
            'e' => self::encode($details['rsa']['e']),
        ];
    }

    /**
     * Mint an RS256-signed ID token. Header overrides let tests produce
     * deliberately broken tokens (unknown kid, unsupported alg, ...).
     *
     * @param array<string, mixed> $claims
     * @param array<string, mixed> $header
     */
    public static function sign(Cache $cache, array $claims, array $header = []): string
    {
        $key = self::key($cache);
        $header = \array_merge([
            'alg' => 'RS256',
            'kid' => self::kid(\openssl_pkey_get_details($key)),
            'typ' => 'JWT',
        ], $header);

        $headerEncoded = self::encode(\json_encode($header));
        $payloadEncoded = self::encode(\json_encode($claims));

        \openssl_sign($headerEncoded . '.' . $payloadEncoded, $signature, $key, OPENSSL_ALGO_SHA256);

        return $headerEncoded . '.' . $payloadEncoded . '.' . self::encode($signature);
    }

    private static function key(Cache $cache): \OpenSSLAsymmetricKey
    {
        $cached = $cache->load(self::CACHE_KEY, self::TTL);
        if (\is_array($cached) && \is_string($cached['pem'] ?? null)) {
            $key = \openssl_pkey_get_private($cached['pem']);
            if ($key !== false) {
                return $key;
            }
        }

        $key = \openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        \openssl_pkey_export($key, $pem);
        $cache->save(self::CACHE_KEY, ['pem' => $pem]);

        return $key;
    }

    /**
     * @param array<string, mixed> $details
     */
    private static function kid(array $details): string
    {
        return \substr(\sha1($details['rsa']['n']), 0, 16);
    }

    private static function encode(string $data): string
    {
        return \rtrim(\strtr(\base64_encode($data), '+/', '-_'), '=');
    }
}
