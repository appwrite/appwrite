<?php

namespace Appwrite\Database\Pool;

use Appwrite\Database\Pool;
use SplQueue;

use Redis;

class RedisPool extends Pool
{
    public function __construct(int $size, string $host, int $port, array $auth = [])
    {
        $this->pool = new SplQueue;
        $this->size = $size;
        for ($i=0; $i < $this->size; $i++) { 
            $redis = new Redis();
            $redis->pconnect($host, $port);
            $redis->setOption(Redis::OPT_READ_TIMEOUT, -1);
            
            if ($auth) {
                $redis->auth($auth);
            }

            $this->pool->enqueue($redis);
        }
    }

    public function put (Redis $redis)
    {
        $this->pool->enqueue($redis);
    }

    public function get (): Redis
    {
        if ($this->available && !$this->pool->isEmpty()) {
            return $this->pool->dequeue();
        }
        sleep(0.1);
        return $this->get();
    }
}
