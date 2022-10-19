<?php

namespace FunctionsProxy\Adapter;

use FunctionsProxy\Adapter;
use Swoole\Database\RedisPool;
use Swoole\Atomic;

class RoundRobin extends Adapter
{
    private Atomic $counter;

    public function __construct(RedisPool $redisPool)
    {
        parent::__construct($redisPool);
        $this->counter = new Atomic(-1);
    }

    public function getNextExecutor(?string $contaierId): array
    {
        $index = $this->counter->add();
        $executors = $this->getExecutors();
        $executor = $executors[$index] ?? null;

        if (!$executor) {
            $executor = $executors[0];
            $this->counter->cmpset($index, 0);
        }

        return $executor ?? null;
    }
}
