<?php

namespace FunctionsProxy\Adapter;

use FunctionsProxy\Adapter;
use Swoole\Database\RedisPool;
use Swoole\Atomic;

class RoundRobin extends Adapter
{
    private Atomic $counter;

    function __construct(RedisPool $redisPool)
    {
        parent::__construct($redisPool);
        $this->counter = new Atomic(-1);
    }

    public function getNextExecutor(?string $contaierId): array
    {
        $count = $this->counter->add();
        $executors = $this->getExecutors();
        $index = $count % \count($executors);
        $executor = $executors[$index];

        // Reset from time to time to prevent memory leak / int overflow
        if ($count > 10000) {
            $this->counter->set(-1);
        }

        return ($executor ?? $executor[0]) ?? null;
    }
}
