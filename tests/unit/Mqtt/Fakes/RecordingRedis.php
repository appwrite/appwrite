<?php

declare(strict_types=1);

namespace Tests\Unit\Mqtt\Fakes;

/** A Redis client that records the keys the CONNECT limiter counts on and never limits. */
final class RecordingRedis extends \Redis
{
    /** @var list<string> */
    public array $keys = [];

    public function eval(string $script, array $args = [], int $num_keys = 0): mixed
    {
        \array_push($this->keys, ...\array_slice($args, 0, $num_keys));

        return 0;
    }
}
