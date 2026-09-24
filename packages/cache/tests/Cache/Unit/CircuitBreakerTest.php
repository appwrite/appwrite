<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Utopia\Cache\Adapter;
use Utopia\Cache\Adapter\CircuitBreaker;
use Utopia\Cache\Adapter\Memory;
use Utopia\Cache\Adapter\Sharding;
use Utopia\Cache\Cache;
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

    public function testReturnsFallbacksWhenCacheOperationsFail(): void
    {
        $this->assertFalse($this->failingCache()->load('key', 60));
        $this->assertFalse($this->failingCache()->save('key', 'value'));
        $this->assertFalse($this->failingCache()->touch('key'));
        $this->assertSame([], $this->failingCache()->list('key'));
        $this->assertFalse($this->failingCache()->purge('key'));
        $this->assertFalse($this->failingCache()->flush());
        $this->assertFalse($this->failingCache()->ping());
        $this->assertSame(0, $this->failingCache()->getSize());
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
        $adapter = new class extends Memory implements Feature\Telemetry {
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

    public function load(string $key, int $ttl, string $hash = ''): mixed
    {
        $this->loads++;

        return parent::load($key, $ttl, $hash);
    }
}
