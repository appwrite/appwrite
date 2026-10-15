<?php

namespace Appwrite\Auth\OIDC\Mock;

use Appwrite\Extend\Exception;
use Appwrite\Locking\Lock;
use Utopia\Cache\Cache;

/**
 * RSA key pair of the mock OpenID provider. It is generated on first use and
 * shared between workers through the cache, so no private key lives in the
 * repository. Creation happens under a lock that re-reads the cache first:
 * every concurrent first request must end up signing with the key the JWKS
 * route publishes.
 */
final readonly class SigningKey
{
    private const string CACHE_KEY = 'oidc-mock-signing-key';

    private const int CACHE_TTL = 86400;

    private const string LOCK_KEY = 'lock:mock:oidc-signing-key';

    private const string LOCK_TARGET = 'mock';

    private const int LOCK_TTL_SECONDS = 30;

    private const float LOCK_WAIT_SECONDS = 30.0;

    public function __construct(
        private Cache $cache,
        private Lock $lock,
    ) {
    }

    public function get(): \OpenSSLAsymmetricKey
    {
        $key = $this->load() ?? $this->lock->withKey(
            self::LOCK_KEY,
            $this->loadOrCreate(...),
            target: self::LOCK_TARGET,
            ttl: self::LOCK_TTL_SECONDS,
            waitTimeout: self::LOCK_WAIT_SECONDS,
        );
        if (!$key instanceof \OpenSSLAsymmetricKey) {
            throw new Exception(Exception::GENERAL_MOCK, 'Mock signing key unavailable');
        }

        return $key;
    }

    /**
     * Derived from the modulus, so a regenerated key gets a new kid and
     * verifiers holding a stale JWKS refresh instead of failing.
     */
    public static function getId(\OpenSSLAsymmetricKey $key): string
    {
        return \substr(\sha1(\openssl_pkey_get_details($key)['rsa']['n']), 0, 16);
    }

    private function loadOrCreate(): \OpenSSLAsymmetricKey
    {
        return $this->load() ?? $this->create();
    }

    private function load(): ?\OpenSSLAsymmetricKey
    {
        $cached = $this->cache->load(self::CACHE_KEY, self::CACHE_TTL);
        if (!\is_array($cached) || !\is_string($cached['pem'] ?? null)) {
            return null;
        }

        return \openssl_pkey_get_private($cached['pem']) ?: null;
    }

    private function create(): \OpenSSLAsymmetricKey
    {
        $key = \openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($key === false || !\openssl_pkey_export($key, $pem)) {
            throw new Exception(Exception::GENERAL_MOCK, 'Failed to create the mock signing key');
        }

        $this->cache->save(self::CACHE_KEY, ['pem' => $pem]);

        return $key;
    }
}
