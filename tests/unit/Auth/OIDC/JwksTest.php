<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\OIDC;

use Appwrite\Auth\OIDC\Jwks;
use Appwrite\Auth\OIDC\JwksException;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\Memory;
use Utopia\Cache\Cache;

final class JwksTest extends TestCase
{
    private const URL = 'https://example.com/jwks';

    public function testKnownKidIsServedFromCacheAfterOneFetch(): void
    {
        $fetches = 0;
        $jwks = new Jwks(new Cache(new Memory()), function () use (&$fetches): string {
            $fetches++;

            return $this->document(['kid-1']);
        });

        $this->assertSame(['n' => 'n-kid-1', 'e' => 'AQAB'], $jwks->getKey(self::URL, 'kid-1'));
        $this->assertSame(['n' => 'n-kid-1', 'e' => 'AQAB'], $jwks->getKey(self::URL, 'kid-1'));
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

        $this->assertNotNull($jwks->getKey(self::URL, 'kid-old'));
        $this->assertSame(['n' => 'n-kid-new', 'e' => 'AQAB'], $jwks->getKey(self::URL, 'kid-new'));
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

    public function testNonRsaAndEncryptionKeysAreSkipped(): void
    {
        $jwks = new Jwks(new Cache(new Memory()), fn (): string => \json_encode(['keys' => [
            ['kty' => 'EC', 'kid' => 'ec-key', 'crv' => 'P-256', 'x' => 'x', 'y' => 'y'],
            ['kty' => 'RSA', 'kid' => 'enc-key', 'use' => 'enc', 'n' => 'n', 'e' => 'AQAB'],
            ['kty' => 'RSA', 'kid' => 'no-material'],
            ['kty' => 'RSA', 'kid' => 'sig-key', 'use' => 'sig', 'n' => 'n-sig', 'e' => 'AQAB'],
        ]]));

        $this->assertSame(['n' => 'n-sig', 'e' => 'AQAB'], $jwks->getKey(self::URL, 'sig-key'));
        $this->assertNull($jwks->getKey(self::URL, 'ec-key'));
        $this->assertNull($jwks->getKey(self::URL, 'enc-key'));
        $this->assertNull($jwks->getKey(self::URL, 'no-material'));
    }

    public function testInvalidDocumentThrows(): void
    {
        $jwks = new Jwks(new Cache(new Memory()), fn (): string => 'not json');

        $this->expectException(JwksException::class);

        $jwks->getKey(self::URL, 'kid-1');
    }

    private function document(array $kids): string
    {
        return \json_encode(['keys' => \array_map(fn (string $kid): array => [
            'kty' => 'RSA',
            'use' => 'sig',
            'kid' => $kid,
            'n' => 'n-' . $kid,
            'e' => 'AQAB',
        ], $kids)]);
    }
}
