<?php

namespace Appwrite\Database\Pool;

use Appwrite\Database\Pool;
use Appwrite\Extend\PDO;
use Swoole\Coroutine\Channel;

class PDOPool extends Pool
{
    public function __construct(int $size, string $host = 'localhost', string $schema = 'appwrite', string $user = '', string $pass = '', string $charset = 'utf8mb4')
    {
        $this->pool = new Channel($this->size = $size);
        for ($i = 0; $i < $this->size; $i++) {
            $pdo = new PDO(
                "mysql:" .
                    "host={$host};" .
                    "dbname={$schema};" .
                    "charset={$charset}",
                $user,
                $pass,
                [
                    PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4',
                    PDO::ATTR_TIMEOUT => 3, // Seconds
                    PDO::ATTR_PERSISTENT => true,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true
                ]
            );
            $this->pool->push($pdo);
        }
    }

    public function put(PDO $pdo)
    {
        $this->pool->push($pdo);
    }

    public function get(): PDO
    {
        if ($this->available && !$this->pool->isEmpty()) {
            return $this->pool->pop();
        }
        sleep(0.01);
        return $this->get();
    }
}
