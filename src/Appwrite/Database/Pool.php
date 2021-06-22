<?php
namespace Appwrite\Database;

use Swoole\Coroutine\Channel;

abstract class Pool
{
    protected Channel $pool;
    protected $available = true;
    protected $size = 5;

    abstract public function get();

    public function destruct()
    {
        $this->available = false;
        while (!$this->pool->isEmpty()) {
            $this->pool->pop();
        }
    }
}
