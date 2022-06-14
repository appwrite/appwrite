<?php

namespace Appwrite\Database;

use PDO;
use Appwrite\Extend\Exception;
use Swoole\Database\PDOPool;
use Swoole\Database\PDOProxy;

class DatabasePool {

    protected array $pools = [];

    public function add(string $name, PDOPool $dbPool): void
    {
        $this->pools[$name] = $dbPool;
    }

    public function get(string $name = 'console'): ?PDOProxy
    {
        $pool = $this->pools[$name] ?? null;
        if ($pool === null) {
            throw new Exception("Database Pool with name : $name not found. Please check the value of _APP_PROJECT_DB in .env", 500);
        }
        return $pool->get();
    }

    public function put(PDOProxy $db, string $name = 'console'): void
    {
        $pool = $this->pools[$name] ?? null;
        if ($pool === null) {
            throw new Exception("Database Pool with name : $name not found. Cannot put", 500);
        }
        $pool->put($db);
    }

}