<?php

declare(strict_types=1);

namespace Utopia\Cache\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Utopia\Cache\Adapter;
use Utopia\Cache\Adapter\CircuitBreaker;
use Utopia\Cache\Adapter\Memory;
use Utopia\Cache\Adapter\Sharding;
use Utopia\Cache\Cache;
use Utopia\Cache\Exception\Unavailable;
use Utopia\Cache\Feature;
use Utopia\CircuitBreaker\CircuitBreaker as UtopiaCircuitBreaker;
use Utopia\Telemetry\Adapter as Telemetry;
use Utopia\Telemetry\Adapter\Test as TestTelemetry;

final class CircuitBreakerTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function coordinationOperations(): iterable
    {
        foreach (['getVersion', 'loadVersioned', 'compareAndSwap'] as $operation) {
            yield $operation => [$operation];
        }
    }

    #[DataProvider('coordinationOperations')]
    public function testOpenCircuitRejectsCoordinationWithoutCallingTheBackend(string $operation): void
    {
        $backend = new CoordinationMemory();
        $version = $backend->getVersion('key');
        $breaker = new UtopiaCircuitBreaker(timeout: 3600);
        $breaker->trip();
        $cache = new CircuitBreaker($backend, $breaker);
        $before = $backend->calls;
        try {
            $operation === 'compareAndSwap'
                ? $cache->compareAndSwap('key', 'value', '', $version)
                : $cache->{$operation}('key');
            $this->fail('An unavailable coordination record must not read as a miss');
        } catch (Unavailable) {
            $this->assertSame($before, $backend->calls);
            $this->assertFalse($backend->load('key', 60));
        }
    }

    #[DataProvider('coordinationOperations')]
    public function testCoordinationFailuresOpenTheCircuitAndRecoveryUsesTheBackend(string $operation): void
    {
        $backend = new CoordinationMemory();
        $version = $backend->getVersion('key');
        $breaker = new UtopiaCircuitBreaker(timeout: 0, minimumThroughput: 1);
        $cache = new CircuitBreaker($backend, $breaker);
        $backend->failing = true;
        try {
            $operation === 'compareAndSwap'
                ? $cache->compareAndSwap('key', 'value', '', $version)
                : $cache->{$operation}('key');
            $this->fail('A transport failure must not read as eviction or a CAS conflict');
        } catch (Unavailable) {
            $this->assertSame(1, $breaker->getFailureCount());
        }
        $backend->failing = false;
        $version = $cache->getVersion('key');
        $committed = $cache->compareAndSwap('key', 'recovered', '', $version);
        $this->assertNotFalse($committed);
        $this->assertSame(['version' => $committed, 'value' => 'recovered'], $cache->loadVersioned('key'));
    }

    public function testPassesThroughHealthyCacheOperations(): void
    {
        $adapter = new Memory();
        $cache = new CircuitBreaker($adapter, new UtopiaCircuitBreaker());

        $this->assertSame('value', $cache->save('key', 'value'));
        $this->assertSame('value', $cache->load('key', 60));
        $this->assertTrue($cache->touch('key'));
        $this->assertSame(1, $cache->getSize());
        $this->assertTrue($cache->ping());
        $this->assertTrue($cache->purge('key'));
        $this->assertFalse($cache->load('key', 60));
    }

    public function testReturnsFallbacksWhenCacheOperationsFail(): void
    {
        $this->assertFalse($this->failingCache()->load('key', 60));
        $this->assertFalse($this->failingCache()->save('key', 'value'));
        $this->assertFalse($this->failingCache()->touch('key'));
        $this->assertSame([], $this->failingCache()->list('key'));
        $this->assertFalse($this->failingCache()->flush());
        $this->assertFalse($this->failingCache()->ping());
        $this->assertSame(0, $this->failingCache()->getSize());
    }

    public function testAFailedPurgeThrowsInsteadOfReportingNothingToPurge(): void
    {
        $cache = $this->failingCache();

        try {
            $cache->purge('key');
            $this->fail('A purge the adapter failed must not read as a purge of a missing key');
        } catch (Unavailable $error) {
            $this->assertInstanceOf(RuntimeException::class, $error->getPrevious());
            $this->assertSame('Cache failed.', $error->getPrevious()->getMessage());
        }
    }

    public function testAFailedPurgeCountsTowardsOpeningTheCircuit(): void
    {
        $breaker = new UtopiaCircuitBreaker(minimumThroughput: 1);
        $cache = new CircuitBreaker(new FailingAdapter(), $breaker);

        try {
            $cache->purge('key');
        } catch (Unavailable) {
        }

        $this->assertTrue($breaker->isOpen());
    }

    public function testAPurgeWhileTheCircuitIsOpenThrowsWithoutReachingTheAdapter(): void
    {
        $adapter = new CountingFailingAdapter();
        $breaker = new UtopiaCircuitBreaker(timeout: 3600);
        $breaker->trip();
        $cache = new CircuitBreaker($adapter, $breaker);

        $this->expectException(Unavailable::class);

        try {
            $cache->purge('key');
        } finally {
            $this->assertSame(0, $adapter->purges);
        }
    }

    public function testOtherOperationsKeepTheirFallbacksWhileTheCircuitIsOpen(): void
    {
        $breaker = new UtopiaCircuitBreaker(timeout: 3600);
        $breaker->trip();
        $cache = new CircuitBreaker(new Memory(), $breaker);

        $this->assertFalse($cache->load('key', 60));
        $this->assertFalse($cache->save('key', 'value'));
        $this->assertFalse($cache->touch('key'));
        $this->assertSame([], $cache->list('key'));
        $this->assertFalse($cache->flush());
        $this->assertFalse($cache->ping());
        $this->assertSame(0, $cache->getSize());
    }

    public function testPurgingAMissingKeyStillReturnsFalse(): void
    {
        $cache = new CircuitBreaker(new Memory(), new UtopiaCircuitBreaker());

        $this->assertFalse($cache->purge('missing'));
    }

    public function testBreakerShortCircuitsAfterFailure(): void
    {
        $adapter = new CountingFailingAdapter();
        $cache = new CircuitBreaker($adapter, new UtopiaCircuitBreaker(minimumThroughput: 1));

        $this->assertFalse($cache->load('key', 60));
        $this->assertFalse($cache->load('key', 60));
        $this->assertSame(1, $adapter->loads);
    }

    public function testTelemetryCanBeAttachedAfterConstruction(): void
    {
        $telemetry = new TestTelemetry();
        $cache = new CircuitBreaker(new Memory(), new UtopiaCircuitBreaker());

        $cache->setTelemetry($telemetry);

        $this->assertFalse($cache->load('missing', 60));
        /** @var object{values: list<int|float>} $calls */
        $calls = $telemetry->counters['breaker.calls'];
        $this->assertSame([1], $calls->values);
    }

    public function testTelemetryPropagatesToInnerAdapter(): void
    {
        $telemetry = new TestTelemetry();
        $adapter = new class () extends Memory implements Feature\Telemetry {
            public ?Telemetry $telemetry = null;

            public function setTelemetry(Telemetry $telemetry): void
            {
                $this->telemetry = $telemetry;
            }
        };
        $cache = new CircuitBreaker($adapter, new UtopiaCircuitBreaker());

        $cache->setTelemetry($telemetry);

        $this->assertSame($telemetry, $adapter->telemetry);
    }

    public function testCacheTelemetryDoesNotPropagateThroughSharding(): void
    {
        $telemetry = new TestTelemetry();
        $cache = new Cache(new Sharding([
            new CircuitBreaker(new Memory(), new UtopiaCircuitBreaker()),
        ]));

        $cache->setTelemetry($telemetry);

        $this->assertSame('value', $cache->save('key', 'value'));
        /** @var object{values: list<int|float>} $operationDuration */
        $operationDuration = $telemetry->histograms['cache.operation.duration'];
        $this->assertArrayNotHasKey('breaker.calls', $telemetry->counters);
        $this->assertCount(1, $operationDuration->values);
    }

    private function failingCache(): CircuitBreaker
    {
        return new CircuitBreaker(new FailingAdapter(), new UtopiaCircuitBreaker(minimumThroughput: 1));
    }
}

class FailingAdapter implements Adapter
{
    public function load(string $key, int $ttl, string $hash = ''): mixed
    {
        throw new RuntimeException('Cache failed.');
    }

    public function save(string $key, array|string $data, string $hash = '', int $ttl = 0): bool|string|array
    {
        throw new RuntimeException('Cache failed.');
    }

    public function touch(string $key, string $hash = ''): bool
    {
        throw new RuntimeException('Cache failed.');
    }

    public function list(string $key): array
    {
        throw new RuntimeException('Cache failed.');
    }

    public function purge(string $key, string $hash = ''): bool
    {
        throw new RuntimeException('Cache failed.');
    }

    public function flush(): bool
    {
        throw new RuntimeException('Cache failed.');
    }

    public function ping(): bool
    {
        throw new RuntimeException('Cache failed.');
    }

    public function getSize(): int
    {
        throw new RuntimeException('Cache failed.');
    }

    public function getName(?string $key = null): string
    {
        return 'failing';
    }
}

class CountingFailingAdapter extends FailingAdapter
{
    public int $loads = 0;

    public int $purges = 0;

    public function purge(string $key, string $hash = ''): bool
    {
        $this->purges++;

        return parent::purge($key, $hash);
    }

    public function load(string $key, int $ttl, string $hash = ''): mixed
    {
        $this->loads++;

        return parent::load($key, $ttl, $hash);
    }
}

/** Real in-process cache with a controllable transport failure. */
final class CoordinationMemory extends Memory
{
    public int $calls = 0;
    public bool $failing = false;

    private function check(): void
    {
        $this->calls++;
        if ($this->failing) {
            throw new RuntimeException('Transport unavailable');
        }
    }

    #[\Override]
    public function getVersion(string $key): string
    {
        $this->check();
        return parent::getVersion($key);
    }

    #[\Override]
    public function loadVersioned(string $key): array
    {
        $this->check();
        return parent::loadVersioned($key);
    }

    #[\Override]
    public function compareAndSwap(string $key, array|string $data, string $hash, string $version): string|false
    {
        $this->check();
        return parent::compareAndSwap($key, $data, $hash, $version);
    }
}
