<?php

namespace Appwrite\Cache\Adapter;

use Utopia\Cache\Adapter;
use Utopia\CircuitBreaker\CircuitBreaker as UtopiaCircuitBreaker;

class CircuitBreaker implements Adapter
{
    public function __construct(
        private readonly Adapter $adapter,
        private readonly UtopiaCircuitBreaker $breaker,
    ) {
    }

    public function load(string $key, int $ttl, string $hash = ''): mixed
    {
        return $this->breaker->call(
            open: fn (): false => false,
            close: fn (): mixed => $this->adapter->load($key, $ttl, $hash),
        );
    }

    public function save(string $key, array|string $data, string $hash = ''): bool|string|array
    {
        return $this->breaker->call(
            open: fn (): false => false,
            close: fn (): bool|string|array => $this->adapter->save($key, $data, $hash),
        );
    }

    public function list(string $key): array
    {
        return $this->breaker->call(
            open: fn (): array => [],
            close: fn (): array => $this->adapter->list($key),
        );
    }

    public function purge(string $key, string $hash = ''): bool
    {
        return $this->breaker->call(
            open: fn (): bool => false,
            close: fn (): bool => $this->adapter->purge($key, $hash),
        );
    }

    public function flush(): bool
    {
        return $this->breaker->call(
            open: fn (): bool => false,
            close: fn (): bool => $this->adapter->flush(),
        );
    }

    public function ping(): bool
    {
        return $this->breaker->call(
            open: fn (): bool => false,
            close: fn (): bool => $this->adapter->ping(),
        );
    }

    public function getSize(): int
    {
        return $this->breaker->call(
            open: fn (): int => 0,
            close: fn (): int => $this->adapter->getSize(),
        );
    }

    public function getName(?string $key = null): string
    {
        try {
            return $this->adapter->getName($key);
        } catch (\Throwable) {
            return 'circuit-breaker';
        }
    }

    public function isOpen(): bool
    {
        return $this->breaker->isOpen();
    }

    public function setMaxRetries(int $maxRetries): self
    {
        $this->adapter->setMaxRetries($maxRetries);

        return $this;
    }

    public function setRetryDelay(int $retryDelay): self
    {
        $this->adapter->setRetryDelay($retryDelay);

        return $this;
    }

    public function getMaxRetries(): int
    {
        return $this->adapter->getMaxRetries();
    }

    public function getRetryDelay(): int
    {
        return $this->adapter->getRetryDelay();
    }
}
