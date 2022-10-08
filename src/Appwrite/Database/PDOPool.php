<?php

namespace Appwrite\Database;

use Swoole\Database\PDOConfig;
use Swoole\Database\PDOPool as SwoolePDOPool;

class PDOPool
{
    private SwoolePDOPool $pool;

    private string $name;

    private array $activeConnections = [];

    public function __construct(PDOConfig $pdoConfig, string $name, int $size = SwoolePDOPool::DEFAULT_SIZE)
    {
        $this->pool = new SwoolePDOPool($pdoConfig, $size);
        $this->name = $name;
    }

    public function getActiveConnections()
    {
        return $this->activeConnections;
    }

    public function get(float $timeout = -1): PDOWrapper
    {
        $pdo = $this->pool->get($timeout);
        $this->activeConnections[] = $pdo;
        return new PDOWrapper($pdo, $this->name);
    }

    public function put(PDOWrapper $pdo): void
    {
        $this->pool->put($pdo->getConnection());
        unset($this->activeConnections[array_search($pdo, $this->activeConnections)]);
    }

    public function reset(): void
    {
        foreach ($this->activeConnections as $connection) {
            $this->pool->put($connection);
        }
        $this->activeConnections = [];
    }
}
