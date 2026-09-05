<?php

namespace Appwrite\Auth\OIDC;

use Utopia\Cache\Cache;

/**
 * Fetches and caches a provider's JSON Web Key Set.
 *
 * Keys are cached per JWKS URL. An unknown `kid` triggers exactly one forced
 * refetch (handles provider key rotation) guarded by a cooldown marker, so
 * requests carrying bogus key IDs cannot hammer the provider endpoint.
 */
class Jwks
{
    public const TTL = 21600; // 6 hours
    public const REFRESH_COOLDOWN = 60; // seconds between forced refetches per URL

    /**
     * @param ?callable $fetcher `fn (string $url): string` returning the raw JWKS body; HTTP GET when null
     */
    public function __construct(
        private Cache $cache,
        private mixed $fetcher = null,
    ) {
    }

    /**
     * @return array{n: string, e: string}|null RSA key material for `$kid`, or null when unknown
     * @throws JwksException when the JWKS document cannot be fetched or parsed
     */
    public function getKey(string $jwksUrl, string $kid): ?array
    {
        $cacheKey = 'oidc-jwks:' . \md5($jwksUrl);

        $keys = $this->cache->load($cacheKey, self::TTL);
        if (!\is_array($keys)) {
            $keys = $this->fetch($jwksUrl);
            $this->cache->save($cacheKey, $keys);
        }

        if (isset($keys[$kid])) {
            return $keys[$kid];
        }

        $cooldownKey = 'oidc-jwks-refresh:' . \md5($jwksUrl);
        if ($this->cache->load($cooldownKey, self::REFRESH_COOLDOWN) !== false) {
            return null;
        }
        $this->cache->save($cooldownKey, [\time()]);

        $keys = $this->fetch($jwksUrl);
        $this->cache->save($cacheKey, $keys);

        return $keys[$kid] ?? null;
    }

    /**
     * @return array<string, array{n: string, e: string}> signature-capable RSA keys, indexed by kid
     * @throws JwksException
     */
    private function fetch(string $jwksUrl): array
    {
        $body = $this->fetcher !== null
            ? ($this->fetcher)($jwksUrl)
            : $this->fetchHttp($jwksUrl);

        $document = \json_decode($body, true);
        if (!\is_array($document) || !\is_array($document['keys'] ?? null)) {
            throw new JwksException('Invalid JWKS document');
        }

        $keys = [];
        foreach ($document['keys'] as $key) {
            if (!\is_array($key)) {
                continue;
            }
            if (($key['kty'] ?? '') !== 'RSA' || !\in_array($key['use'] ?? null, [null, 'sig'], true)) {
                continue;
            }
            $kid = $key['kid'] ?? null;
            $n = $key['n'] ?? null;
            $e = $key['e'] ?? null;
            if (!\is_string($kid) || $kid === '' || !\is_string($n) || !\is_string($e)) {
                continue;
            }
            $keys[$kid] = ['n' => $n, 'e' => $e];
        }

        return $keys;
    }

    /**
     * @throws JwksException
     */
    private function fetchHttp(string $jwksUrl): string
    {
        $ch = \curl_init($jwksUrl);

        \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        \curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        \curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        \curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        \curl_setopt($ch, CURLOPT_USERAGENT, 'Appwrite');

        $body = \curl_exec($ch);
        $code = (int) \curl_getinfo($ch, CURLINFO_HTTP_CODE);

        \curl_close($ch);

        if (!\is_string($body) || $code >= 400 || $code === 0) {
            throw new JwksException('Failed to fetch JWKS');
        }

        return $body;
    }
}
