<?php

namespace Appwrite\Database;

use Swoole\Database\PDOConfig;
use Swoole\Database\PDOPool as SwoolePDOPool;


class PDOPool
{
    private array $activeConnections = [];

    private SwoolePDOPool $pool;

    public function __construct(PDOConfig $pdoConfig, int $size = SwoolePDOPool::DEFAULT_SIZE)
    {
        $this->pool = new SwoolePDOPool($pdoConfig, $size);
    }

    public function getActiveConnections()
    {
        return $this->activeConnections;
    }

    public function get(float $timeout = -1)
    {
        $connection = $this->pool->get($timeout);
        $this->activeConnections[] = $connection;
        return $connection;
    }

    public function put($connection): void
    {
        $this->pool->put($connection);
        unset($this->activeConnections[array_search($connection, $this->activeConnections)]);
    }

    public function reset(): void
    {
        foreach($this->activeConnections as $connection) {
            $this->pool->put($connection);
        }

        $this->activeConnections = [];
    }
}