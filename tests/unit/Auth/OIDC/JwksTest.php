<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\OIDC;

use Appwrite\Auth\OIDC\Jwks;
use Appwrite\Extend\Exception;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\Memory;
use Utopia\Cache\Cache;

final class JwksTest extends TestCase
{
    private const URL = 'https://example.com/jwks';
    private const PEM_HEADER = '-----BEGIN PUBLIC KEY-----';

    public function testKnownKidIsServedFromCacheAfterOneFetch(): void
    {
        $fetches = 0;
        $jwks = new Jwks(new Cache(new Memory()), function () use (&$fetches): string {
            $fetches++;

            return $this->document(['kid-1']);
        });

        $pem = $jwks->getKey(self::URL, 'kid-1');

        $this->assertStringStartsWith(self::PEM_HEADER, $pem);
        $this->assertSame($pem, $jwks->getKey(self::URL, 'kid-1'));
        $this->assertSame(1, $fetches);
    }

    /**
     * Key rotation: a kid missing from the cached set forces exactly one
     * refetch. If the provider now serves it, the key is returned.
     */
    public function testUnknownKidTriggersOneForcedRefetch(): void
    {
        $fetches = 0;
        $jwks = new Jwks(new Cache(new Memory()), function () use (&$fetches): string {
            $fetches++;

            return $this->document($fetches === 1 ? ['kid-old'] : ['kid-old', 'kid-new']);
        });

        $old = $jwks->getKey(self::URL, 'kid-old');
        $new = $jwks->getKey(self::URL, 'kid-new');

        $this->assertStringStartsWith(self::PEM_HEADER, $old);
        $this->assertStringStartsWith(self::PEM_HEADER, $new);
        $this->assertNotSame($old, $new);
        $this->assertSame(2, $fetches);
    }

    /**
     * Bogus kids must not let a caller hammer the provider: after one forced
     * refetch the cooldown suppresses further fetches and the lookup fails.
     */
    public function testCooldownSuppressesRepeatedForcedRefetches(): void
    {
        $fetches = 0;
        $jwks = new Jwks(new Cache(new Memory()), function () use (&$fetches): string {
            $fetches++;

            return $this->document(['kid-1']);
        });

        $this->assertNull($jwks->getKey(self::URL, 'bogus-a'));
        $this->assertNull($jwks->getKey(self::URL, 'bogus-b'));
        $this->assertNull($jwks->getKey(self::URL, 'bogus-c'));
        $this->assertSame(2, $fetches); // initial load + one forced refetch
    }

    public function testUnusableKeysAreSkipped(): void
    {
        $jwks = new Jwks(new Cache(new Memory()), fn (): string => \json_encode(['keys' => [
            ['kty' => 'EC', 'kid' => 'ec-key', 'crv' => 'P-256', 'x' => 'x', 'y' => 'y'],
            ['kty' => 'RSA', 'kid' => 'enc-key', 'use' => 'enc', 'n' => 'AQID', 'e' => 'AQAB'],
            ['kty' => 'RSA', 'kid' => 'no-material'],
            ['kty' => 'RSA', 'kid' => 'bad-material', 'use' => 'sig', 'n' => 'not base64url!!', 'e' => 'AQAB'],
            ['kty' => 'RSA', 'kid' => 'empty-material', 'use' => 'sig', 'n' => '', 'e' => 'AQAB'],
            ['kty' => 'RSA', 'kid' => 'sig-key', 'use' => 'sig', 'n' => 'AQID', 'e' => 'AQAB'],
        ]]));

        $this->assertStringStartsWith(self::PEM_HEADER, $jwks->getKey(self::URL, 'sig-key'));
        $this->assertNull($jwks->getKey(self::URL, 'ec-key'));
        $this->assertNull($jwks->getKey(self::URL, 'enc-key'));
        $this->assertNull($jwks->getKey(self::URL, 'no-material'));
        $this->assertNull($jwks->getKey(self::URL, 'bad-material'));
        $this->assertNull($jwks->getKey(self::URL, 'empty-material'));
    }

    /**
     * The PEM must describe the same key OpenSSL generated, so a signature
     * made with the private key verifies against the published public key.
     * RSA moduli always have the high bit set, so this also covers the DER
     * leading-zero rule that keeps the INTEGER positive.
     */
    public function testPemRoundTripsThroughOpenSsl(): void
    {
        $key = \openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $this->assertNotFalse($key);
        $details = \openssl_pkey_get_details($key);

        $jwks = new Jwks(new Cache(new Memory()), fn (): string => \json_encode(['keys' => [[
            'kty' => 'RSA',
            'use' => 'sig',
            'kid' => 'real',
            'n' => $this->base64UrlEncode($details['rsa']['n']),
            'e' => $this->base64UrlEncode($details['rsa']['e']),
        ]]]));

        $pem = $jwks->getKey(self::URL, 'real');

        $this->assertSame(\trim($details['key']), \trim($pem));

        \openssl_sign('payload', $signature, $key, OPENSSL_ALGO_SHA256);
        $public = \openssl_pkey_get_public($pem);

        $this->assertNotFalse($public);
        $this->assertSame(1, \openssl_verify('payload', $signature, $public, OPENSSL_ALGO_SHA256));
    }

    public function testInvalidDocumentThrows(): void
    {
        $jwks = new Jwks(new Cache(new Memory()), fn (): string => 'not json');

        try {
            $jwks->getKey(self::URL, 'kid-1');
            $this->fail('An unparsable JWKS document must be rejected');
        } catch (Exception $exception) {
            $this->assertSame(Exception::USER_OAUTH2_PROVIDER_ERROR, $exception->getType());
        }
    }

    /**
     * Distinct, well-formed key material per kid; not a real RSA modulus,
     * which only matters once OpenSSL loads the PEM.
     */
    private function document(array $kids): string
    {
        return \json_encode(['keys' => \array_map(fn (string $kid): array => [
            'kty' => 'RSA',
            'use' => 'sig',
            'kid' => $kid,
            'n' => $this->base64UrlEncode($kid),
            'e' => 'AQAB',
        ], $kids)]);
    }

    private function base64UrlEncode(string $data): string
    {
        return \rtrim(\strtr(\base64_encode($data), '+/', '-_'), '=');
    }
}
