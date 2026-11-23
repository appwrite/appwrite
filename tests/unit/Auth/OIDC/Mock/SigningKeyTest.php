<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\OIDC\Mock;

use Appwrite\Auth\OIDC\Mock\SigningKey;
use Appwrite\Locking\Lock;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\Memory;
use Utopia\Cache\Cache;
use Utopia\Database\Document;
use Utopia\Lock\Mutex;
use Utopia\Telemetry\Adapter\None as NoTelemetry;

final class SigningKeyTest extends TestCase
{
    public function testKeyIsCreatedOnceAndReused(): void
    {
        $cache = new Cache(new Memory());
        $lock = $this->createLock();

        $first = (new SigningKey($cache, $lock))->get();
        $second = (new SigningKey($cache, $lock))->get();

        $this->assertSame($this->getPublicKey($first), $this->getPublicKey($second));
        $this->assertSame(SigningKey::getId($first), SigningKey::getId($second));
    }

    public function testSeparateCachesGetDifferentKeysAndIds(): void
    {
        $first = (new SigningKey(new Cache(new Memory()), $this->createLock()))->get();
        $second = (new SigningKey(new Cache(new Memory()), $this->createLock()))->get();

        $this->assertNotSame($this->getPublicKey($first), $this->getPublicKey($second));
        $this->assertNotSame(SigningKey::getId($first), SigningKey::getId($second));
    }

    /**
     * Two workers miss the cache at the same time. The second one creates and
     * caches its key while the first is between its cache miss and its own
     * creation. Both must sign with the key the cache ends up holding, which
     * is the one the JWKS route publishes.
     */
    public function testConcurrentFirstUseSharesTheCachedKey(): void
    {
        $lock = $this->createLock();
        $cache = null;
        $concurrent = null;
        $cache = new Cache(new InterruptedMemory(function () use (&$cache, $lock, &$concurrent): void {
            $concurrent = (new SigningKey($cache, $lock))->get();
        }));

        $first = (new SigningKey($cache, $lock))->get();
        $published = (new SigningKey($cache, $lock))->get();

        $this->assertInstanceOf(\OpenSSLAsymmetricKey::class, $concurrent, 'The concurrent first request did not run.');
        $this->assertSame($this->getPublicKey($published), $this->getPublicKey($concurrent), 'The concurrent request signs with a key the JWKS route does not publish.');
        $this->assertSame($this->getPublicKey($published), $this->getPublicKey($first), 'The first request signs with a key the JWKS route does not publish.');
    }

    private function createLock(): Lock
    {
        $mutex = new Mutex();

        return new Lock(
            fn (string $key, int $ttl, \Closure $callback): mixed => $callback($mutex),
            new NoTelemetry(),
            new Document(['$id' => 'console']),
        );
    }

    private function getPublicKey(\OpenSSLAsymmetricKey $key): string
    {
        return \openssl_pkey_get_details($key)['key'];
    }
}
