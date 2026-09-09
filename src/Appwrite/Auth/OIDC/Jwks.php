<?php

namespace Appwrite\Auth\OIDC;

use Appwrite\Extend\Exception;
use Utopia\Cache\Cache;
use Utopia\Fetch\Client;

/**
 * Fetches a provider's JSON Web Key Set and caches its RSA keys as PEM.
 *
 * Keys are cached per JWKS URL. An unknown `kid` triggers exactly one forced
 * refetch (handles provider key rotation) guarded by a cooldown marker, so
 * requests carrying bogus key IDs cannot hammer the provider endpoint.
 */
class Jwks
{
    public const TTL = 21600; // 6 hours
    public const REFRESH_COOLDOWN = 60; // seconds between forced refetches per URL

    private const CONNECT_TIMEOUT = 5 * 1000; // milliseconds
    private const REQUEST_TIMEOUT = 10 * 1000; // milliseconds

    private const RSA_ENCRYPTION_OID = "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01"; // 1.2.840.113549.1.1.1

    /**
     * @param ?callable $fetcher `fn (string $url): string` returning the raw JWKS body; HTTP GET when null
     */
    public function __construct(
        private Cache $cache,
        private mixed $fetcher = null,
    ) {
    }

    /**
     * @return ?string PEM public key for `$kid`, or null when unknown
     * @throws Exception when the JWKS document cannot be fetched or parsed
     */
    public function getKey(string $jwksUrl, string $kid): ?string
    {
        $cacheKey = 'oidc-jwks-pem:' . \md5($jwksUrl);

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
     * Keys that are not RSA, not for signing, or carry unusable material are
     * skipped rather than failing the whole set.
     *
     * @return array<string, string> PEM public keys, indexed by kid
     * @throws Exception
     */
    private function fetch(string $jwksUrl): array
    {
        $body = $this->fetcher !== null
            ? ($this->fetcher)($jwksUrl)
            : $this->fetchHttp($jwksUrl);

        $document = \json_decode($body, true);
        if (!\is_array($document) || !\is_array($document['keys'] ?? null)) {
            throw new Exception(Exception::USER_OAUTH2_PROVIDER_ERROR, 'The provider returned an invalid signing key document. Please try again.');
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
            $modulus = $this->decodeBase64Url($key['n'] ?? null);
            $exponent = $this->decodeBase64Url($key['e'] ?? null);
            if (!\is_string($kid) || $kid === '' || $modulus === false || $modulus === '' || $exponent === false || $exponent === '') {
                continue;
            }
            $keys[$kid] = $this->pem($modulus, $exponent);
        }

        return $keys;
    }

    /**
     * @throws Exception
     */
    private function fetchHttp(string $jwksUrl): string
    {
        try {
            $response = (new Client())
                ->setConnectTimeout(self::CONNECT_TIMEOUT)
                ->setTimeout(self::REQUEST_TIMEOUT)
                ->setAllowRedirects(false)
                ->setUserAgent('Appwrite')
                ->fetch($jwksUrl);
        } catch (\Throwable) {
            throw new Exception(Exception::USER_OAUTH2_PROVIDER_ERROR, 'Failed to fetch the provider signing keys. Please try again.');
        }

        if ($response->getStatusCode() !== 200) {
            throw new Exception(Exception::USER_OAUTH2_PROVIDER_ERROR, 'Failed to fetch the provider signing keys. Please try again.');
        }

        return $response->text();
    }

    private function decodeBase64Url(mixed $data): string|false
    {
        if (!\is_string($data)) {
            return false;
        }

        $remainder = \strlen($data) % 4;
        if ($remainder > 0) {
            $data .= \str_repeat('=', 4 - $remainder);
        }

        return \base64_decode(\strtr($data, '-_', '+/'), true);
    }

    /**
     * Encode RSA public key material as the PEM SubjectPublicKeyInfo that
     * openssl_pkey_get_public() accepts.
     */
    private function pem(string $modulus, string $exponent): string
    {
        $publicKey = $this->sequence($this->integer($modulus) . $this->integer($exponent));
        $algorithm = $this->sequence(self::RSA_ENCRYPTION_OID . "\x05\x00"); // NULL parameters
        $subjectPublicKeyInfo = $this->sequence($algorithm . $this->bitString($publicKey));

        return "-----BEGIN PUBLIC KEY-----\n"
            . \chunk_split(\base64_encode($subjectPublicKeyInfo), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    private function integer(string $bytes): string
    {
        // DER INTEGERs are signed; prepend a zero byte when the high bit is set
        // so the value stays positive.
        if ((\ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00" . $bytes;
        }

        return "\x02" . $this->length(\strlen($bytes)) . $bytes;
    }

    private function sequence(string $bytes): string
    {
        return "\x30" . $this->length(\strlen($bytes)) . $bytes;
    }

    private function bitString(string $bytes): string
    {
        $bytes = "\x00" . $bytes; // zero unused bits

        return "\x03" . $this->length(\strlen($bytes)) . $bytes;
    }

    private function length(int $length): string
    {
        if ($length < 0x80) {
            return \chr($length);
        }

        $bytes = \ltrim(\pack('N', $length), "\x00");

        return \chr(0x80 | \strlen($bytes)) . $bytes;
    }
}
