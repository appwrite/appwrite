<?php

namespace Utopia\Abuse\Tests\TimeLimit;

use Utopia\Abuse\Adapter\TimeLimit;

final readonly class Memory extends TimeLimit
{
    public function __construct(string $key, int $limit, int $seconds, private Store $store)
    {
        parent::__construct($key, $limit, $seconds);
    }

    #[\Override]
    protected function now(): int
    {
        return $this->store->now;
    }

    #[\Override]
    protected function hit(string $key, int $window): int
    {
        $this->store->calls++;
        $used = $this->store->counts[$key . '__' . $window] ?? 0;

        if ($used < $this->limit) {
            $this->store->counts[$key . '__' . $window] = $used + 1;
        }

        return $used;
    }

    #[\Override]
    protected function count(string $key, int $window): int
    {
        $this->store->calls++;

        return $this->store->counts[$key . '__' . $window] ?? 0;
    }

    #[\Override]
    protected function set(string $key, int $window, int $value): void
    {
        $this->store->calls++;
        $this->store->counts[$key . '__' . $window] = $value;
    }

    /**
     * @return array<string, int>
     */
    #[\Override]
    public function getLogs(?int $offset = null, ?int $limit = 25): array
    {
        return \array_slice($this->store->counts, $offset ?? 0, $limit);
    }

    #[\Override]
    public function cleanup(int $timestamp): bool
    {
        return true;
    }
}
