<?php

declare(strict_types=1);

namespace Utopia\Tests\Cdn\Cache;

use PHPUnit\Framework\TestCase;
use Utopia\Cdn\Cache;
use Utopia\Cdn\Cache\Adapter;
use Utopia\Cdn\Cache\Adapter\Balancer;
use Utopia\Cdn\Cache\Adapter\Cloudflare;
use Utopia\Cdn\Cache\Adapter\Fastly;

/**
 * The interface is what keeps the adapters interchangeable, so it is asserted directly. A provider
 * that grew a purge the others lacked, or that spelled one differently, would show up here rather
 * than in a caller that assumed both behaved alike.
 */
final class AdapterTest extends TestCase
{
    private const array OPERATIONS = ['purgePaths', 'purgeDomain', 'purgeKeys', 'purgeZone'];

    public function testEveryAdapterOffersTheSameOperations(): void
    {
        $this->assertSame(self::OPERATIONS, get_class_methods(Adapter::class));

        // Balancer included: a composite adapter that lagged the interface would
        // silently stop forwarding whichever operation it had not caught up with.
        foreach ([Fastly::class, Cloudflare::class, Balancer::class] as $adapter) {
            $this->assertContains(Adapter::class, class_implements($adapter), $adapter . ' must implement the adapter interface');

            foreach (self::OPERATIONS as $operation) {
                $this->assertTrue(method_exists($adapter, $operation), $adapter . ' is missing ' . $operation . '()');
            }
        }
    }

    public function testTheFacadeExposesEveryOperation(): void
    {
        // A purge reachable on an adapter but not through Cache would push callers
        // back to holding concrete adapters, which is what the interface avoids.
        foreach (self::OPERATIONS as $operation) {
            $this->assertTrue(method_exists(Cache::class, $operation), 'Cache is missing ' . $operation . '()');
        }
    }

    public function testBatchCeilingsAreNamedAlikeWhereBothProvidersBatch(): void
    {
        // Same name either side, provider-specific number behind it. Fastly batches
        // keys and purges URLs one at a time, so only Cloudflare names a path ceiling.
        foreach ([Fastly::class, Cloudflare::class] as $adapter) {
            $this->assertTrue(\defined($adapter . '::KEYS_PER_PURGE'), $adapter . ' must declare KEYS_PER_PURGE');
        }

        $this->assertSame(256, Fastly::KEYS_PER_PURGE);
        $this->assertSame(30, Cloudflare::KEYS_PER_PURGE);
        $this->assertSame(30, Cloudflare::PATHS_PER_PURGE);
    }
}
