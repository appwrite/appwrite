<?php

namespace Appwrite\Database\Pool;

use Appwrite\Database\Pool;
use Redis;
use Swoole\Coroutine\Channel;

class RedisPool extends Pool
{
    public function __construct(int $size, string $host, int $port, array $auth = [])
    {
        $this->pool = new Channel($this->size = $size);
        for ($i = 0; $i < $this->size; $i++) {
            $redis = new Redis();
            $redis->pconnect($host, $port);
            $redis->setOption(Redis::OPT_READ_TIMEOUT, -1);

            if ($auth) {
                $redis->auth($auth);
            }

            $this->pool->push($redis);
        }
    }

    public function put(Redis $redis)
    {
        $this->pool->push($redis);
    }

    public function get(): Redis
    {
        if ($this->available && !$this->pool->isEmpty()) {
            return $this->pool->pop();
        }
        sleep(0.1);
        return $this->get();
    }
}
