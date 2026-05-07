<?php

namespace Tests\Unit\Cache\Adapter;

use Appwrite\Cache\Adapter\CircuitBreaker;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Utopia\Cache\Adapter;
use Utopia\Cache\Adapter\Memory;
use Utopia\CircuitBreaker\CircuitBreaker as UtopiaCircuitBreaker;

class CircuitBreakerTest extends TestCase
{
    public function testPassesThroughHealthyCacheOperations(): void
    {
        $adapter = new Memory();
        $cache = new CircuitBreaker($adapter, new UtopiaCircuitBreaker());

        $this->assertSame('value', $cache->save('key', 'value'));
        $this->assertSame('value', $cache->load('key', 60));
        $this->assertSame(1, $cache->getSize());
        $this->assertTrue($cache->ping());
        $this->assertTrue($cache->purge('key'));
        $this->assertFalse($cache->load('key', 60));
    }

    public function testReturnsFallbacksWhenCacheOperationsFail(): void
    {
        $this->assertFalse($this->failingCache()->load('key', 60));
        $this->assertFalse($this->failingCache()->save('key', 'value'));
        $this->assertSame([], $this->failingCache()->list('key'));
        $this->assertFalse($this->failingCache()->purge('key'));
        $this->assertFalse($this->failingCache()->flush());
        $this->assertFalse($this->failingCache()->ping());
        $this->assertSame(0, $this->failingCache()->getSize());
    }

    public function testBreakerShortCircuitsAfterFailure(): void
    {
        $adapter = new CountingFailingAdapter();
        $cache = new CircuitBreaker($adapter, new UtopiaCircuitBreaker(threshold: 1));

        $this->assertFalse($cache->load('key', 60));
        $this->assertFalse($cache->load('key', 60));
        $this->assertSame(1, $adapter->loads);
    }

    private function failingCache(): CircuitBreaker
    {
        return new CircuitBreaker(new FailingAdapter(), new UtopiaCircuitBreaker(threshold: 1));
    }
}

class FailingAdapter implements Adapter
{
    public function load(string $key, int $ttl, string $hash = ''): mixed
    {
        throw new RuntimeException('Cache failed.');
    }

    public function save(string $key, array|string $data, string $hash = ''): bool|string|array
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

    public function setMaxRetries(int $maxRetries): self
    {
        return $this;
    }

    public function setRetryDelay(int $retryDelay): self
    {
        return $this;
    }

    public function getMaxRetries(): int
    {
        return 0;
    }

    public function getRetryDelay(): int
    {
        return 0;
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
