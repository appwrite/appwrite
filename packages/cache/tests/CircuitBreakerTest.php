<?php

declare(strict_types=1);

namespace Utopia\Cache\Tests;

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

    public function testReadsFallBackWhenCacheOperationsFail(): void
    {
        $this->assertFalse($this->failingCache()->load('key', 60));
        $this->assertFalse($this->failingCache()->touch('key'));
        $this->assertSame([], $this->failingCache()->list('key'));
        $this->assertFalse($this->failingCache()->ping());
        $this->assertSame(0, $this->failingCache()->getSize());
        $this->assertSame('0', $this->failingCache()->getGeneration('key'));
        $this->assertFalse($this->failingCache()->saveWithLease('key', 'value', 'hash', '0'));
    }

    public function testWritesThrowWhenCacheOperationsFail(): void
    {
        $writes = [
            'save' => fn (CircuitBreaker $cache): mixed => $cache->save('key', 'value'),
            'purge' => fn (CircuitBreaker $cache): mixed => $cache->purge('key'),
            'flush' => fn (CircuitBreaker $cache): mixed => $cache->flush(),
        ];

        foreach ($writes as $name => $write) {
            try {
                $write($this->failingCache());
                $this->fail("A failed {$name} must throw.");
            } catch (Unavailable $error) {
                $this->assertInstanceOf(RuntimeException::class, $error->getPrevious(), "A failed {$name} keeps its cause.");
                $this->assertStringContainsString('Cache failed.', $error->getMessage());
            }
        }
    }

    public function testWriteReportedAsFalseThrowsAndOpensTheBreaker(): void
    {
        $adapter = new UnstoredWritesAdapter();
        $cache = new CircuitBreaker($adapter, new UtopiaCircuitBreaker(minimumThroughput: 1));

        try {
            $cache->save('key', 'value');
            $this->fail('A save the adapter did not store must throw.');
        } catch (Unavailable $error) {
            $this->assertSame("Cache save of 'key' was not stored", $error->getMessage());
        }

        $this->assertFalse($cache->load('key', 60));
        $this->assertSame(0, $adapter->loads, 'The unstored save counts as a failure, so the breaker opens.');
    }

    public function testSaveManyReportedAsFalseThrows(): void
    {
        $cache = new CircuitBreaker(new UnstoredWritesAdapter(), new UtopiaCircuitBreaker());

        $this->expectException(Unavailable::class);
        $cache->saveMany('key', ['field' => 'value']);
    }

    public function testOpenBreakerThrowsOnWritesWithoutCallingTheAdapter(): void
    {
        $adapter = new CountingFailingAdapter();
        $cache = new CircuitBreaker($adapter, new UtopiaCircuitBreaker(minimumThroughput: 1));
        $this->assertFalse($cache->load('key', 60));

        foreach (['save' => fn (): mixed => $cache->save('key', 'value'), 'purge' => fn (): mixed => $cache->purge('key')] as $name => $write) {
            try {
                $write();
                $this->fail("A {$name} through an open breaker must throw.");
            } catch (Unavailable $error) {
                $this->assertStringContainsString('the cache circuit is open', $error->getMessage());
            }
        }

        $this->assertSame(0, $adapter->writes);
    }

    public function testSavesEveryAdapterRefusesAreNotFailures(): void
    {
        $adapter = new UnstoredWritesAdapter();
        $cache = new CircuitBreaker($adapter, new UtopiaCircuitBreaker(minimumThroughput: 1));

        $this->assertFalse($cache->save('key', ''));
        $this->assertFalse($cache->save('key', '0'));
        $this->assertFalse($cache->save('', 'value'));
        $this->assertFalse($cache->saveMany('key', []));
        $this->assertFalse($cache->load('key', 60));
        $this->assertSame(1, $adapter->loads, 'Refused input never reaches the breaker, so it stays closed.');
    }

    public function testPurgeOfAMissingKeyIsNotAFailure(): void
    {
        $cache = new CircuitBreaker(new Memory(), new UtopiaCircuitBreaker(minimumThroughput: 1));

        $this->assertFalse($cache->purge('missing'));
        $this->assertSame('value', $cache->save('key', 'value'));
    }

    public function testCacheSurfacesWriteFailures(): void
    {
        $cache = new Cache($this->failingCache());

        $this->assertFalse($cache->load('key', 60));
        $this->expectException(Unavailable::class);
        $cache->purge('key');
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

    public int $writes = 0;

    public function load(string $key, int $ttl, string $hash = ''): mixed
    {
        $this->loads++;

        return parent::load($key, $ttl, $hash);
    }

    public function save(string $key, array|string $data, string $hash = '', int $ttl = 0): bool|string|array
    {
        $this->writes++;

        return parent::save($key, $data, $hash, $ttl);
    }

    public function purge(string $key, string $hash = ''): bool
    {
        $this->writes++;

        return parent::purge($key, $hash);
    }
}

/**
 * Reports every write as false, as the Redis adapters do when a write misses its deadline.
 */
class UnstoredWritesAdapter extends Memory implements Feature\Batchable
{
    public int $loads = 0;

    public function load(string $key, int $ttl, string $hash = ''): mixed
    {
        $this->loads++;

        return parent::load($key, $ttl, $hash);
    }

    public function save(string $key, array|string $data, string $hash = '', int $ttl = 0): bool|string|array
    {
        return false;
    }

    public function loadMany(string $key, array $fields, int $ttl): array
    {
        return [];
    }

    public function saveMany(string $key, array $data, int $ttl = 0): array|false
    {
        return false;
    }
}
