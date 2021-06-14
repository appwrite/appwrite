<?php

namespace Appwrite\Database;

abstract class Pool
{
    protected $available = true;
    protected $pool;
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
