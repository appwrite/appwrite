<?php

declare(strict_types=1);

namespace Tests\Unit\Mqtt\Fakes;

/** A Redis client that records the keys the broker's limiters count on, and counts each key's hits as the limiter script does. */
final class RecordingRedis extends \Redis
{
    /** @var list<string> */
    public array $keys = [];

    /** @var array<string, int> key => hits */
    private array $hits = [];

    /** Returns the hits on the key before this one, then counts it. */
    public function eval(string $script, array $args = [], int $num_keys = 0): mixed
    {
        $keys = \array_slice($args, 0, $num_keys);
        \array_push($this->keys, ...$keys);

        $key = (string) ($keys[0] ?? '');
        $before = $this->hits[$key] ?? 0;
        $this->hits[$key] = $before + 1;

        return $before;
    }
}
