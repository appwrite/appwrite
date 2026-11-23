<?php

declare(strict_types=1);

namespace Utopia\Cache\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Utopia\Cache\Adapter\CircuitBreaker;
use Utopia\Cache\Adapter\Memory;
use Utopia\Cache\Adapter\Redis\ConnectionException;
use Utopia\Cache\Adapter\Redis\TimeoutException;
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
            match ($operation) {
                'compareAndSwap' => $cache->compareAndSwap('key', 'value', '', $version),
                default => $cache->{$operation}('key'),
            };
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
        $backend->failure = new RuntimeException('Transport unavailable');
        try {
            match ($operation) {
                'compareAndSwap' => $cache->compareAndSwap('key', 'value', '', $version),
                default => $cache->{$operation}('key'),
            };
            $this->fail('A transport failure must not read as eviction or a CAS conflict');
        } catch (Unavailable) {
            $this->assertSame(1, $breaker->getFailureCount());
        }
        $backend->failure = null;
        $version = $cache->getVersion('key');
        $committed = $cache->compareAndSwap('key', 'recovered', '', $version);
        $this->assertNotFalse($committed);
        $this->assertSame(['version' => $committed, 'value' => 'recovered'], $cache->loadVersioned('key'));
    }

    #[DataProvider('coordinationOperations')]
    public function testSlowCoordinationIsRefusedWithoutOpeningTheCircuit(string $operation): void
    {
        $backend = new CoordinationMemory();
        $breaker = $this->slowAwareBreaker();
        $cache = new CircuitBreaker($backend, $breaker);
        $version = $cache->getVersion('key');
        $backend->failure = new TimeoutException('read deadline expired');

        try {
            $this->coordinate($cache, $operation, $version);
            $this->fail('A slow coordination read must not read as eviction or a CAS conflict');
        } catch (Unavailable) {
            $this->assertTrue($breaker->isClosed());
            $this->assertSame(0, $breaker->getFailureCount());
            $this->assertSame(1, $breaker->getSlowCount());
        }
    }

    #[DataProvider('coordinationOperations')]
    public function testAllSlowCoordinationOpensTheCircuit(string $operation): void
    {
        $backend = new CoordinationMemory();
        $version = $backend->getVersion('key');
        $breaker = $this->slowAwareBreaker();
        $cache = new CircuitBreaker($backend, $breaker);
        $backend->failure = new TimeoutException('read deadline expired');

        try {
            $this->coordinate($cache, $operation, $version);
            $this->fail('A slow coordination read must not read as eviction or a CAS conflict');
        } catch (Unavailable) {
            $this->assertTrue($breaker->isOpen());
        }
    }

    #[DataProvider('coordinationOperations')]
    public function testABrokenConnectionStillOpensThroughTheFailureRule(string $operation): void
    {
        $backend = new CoordinationMemory();
        $version = $backend->getVersion('key');
        $breaker = $this->slowAwareBreaker();
        $cache = new CircuitBreaker($backend, $breaker);
        $backend->failure = new ConnectionException('connection lost');

        try {
            $this->coordinate($cache, $operation, $version);
            $this->fail('A transport failure must not read as eviction or a CAS conflict');
        } catch (Unavailable) {
            $this->assertTrue($breaker->isOpen());
            $this->assertSame(1, $breaker->getFailureCount());
            $this->assertSame(0, $breaker->getSlowCount());
        }
    }

    public function testASlowLoadIsAMissThatKeepsTheCircuitClosed(): void
    {
        $backend = new SlowLoadMemory();
        $breaker = $this->slowAwareBreaker();
        $cache = new CircuitBreaker($backend, $breaker);
        $this->assertSame('value', $cache->save('key', 'value'));
        $backend->slow = true;

        $this->assertFalse($cache->load('key', 60));
        $this->assertTrue($breaker->isClosed());
        $this->assertSame(1, $breaker->getSlowCount());
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

    public function testPassesThroughAnEmptyArray(): void
    {
        $cache = new Cache(new CircuitBreaker(new Memory(), new UtopiaCircuitBreaker()));

        $this->assertSame([], $cache->save('key', []));
        $this->assertSame([], $cache->load('key', 60));
        $this->assertSame([], $cache->saveWithLease('lease', [], '', $cache->getLease('lease')));
        $this->assertSame([], $cache->load('lease', 60));
        $this->assertFalse($cache->save('empty', ''));
        $this->assertFalse($cache->save('zero', '0'));
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

    private function slowAwareBreaker(): UtopiaCircuitBreaker
    {
        return new UtopiaCircuitBreaker(
            timeout: 3600,
            minimumThroughput: 1,
            slow: [TimeoutException::class],
            slowRatio: 0.9,
        );
    }

    private function coordinate(CircuitBreaker $cache, string $operation, string $version): mixed
    {
        return match ($operation) {
            'compareAndSwap' => $cache->compareAndSwap('key', 'value', '', $version),
            default => $cache->{$operation}('key'),
        };
    }

    private function failingCache(): CircuitBreaker
    {
        return new CircuitBreaker(new FailingAdapter(), new UtopiaCircuitBreaker(minimumThroughput: 1));
    }
}
