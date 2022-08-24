<?php

namespace Appwrite\Database;

use Swoole\Database\PDOProxy;

class PDOWrapper
{
    private string $name;
    private PDOProxy $connection;

    public function __construct(PDOProxy $connection, string $name)
    {
        $this->connection = $connection;
        $this->name = $name;
    }

    public function getName()
    {
        return $this->name;
    }

    public function getConnection()
    {
        return $this->connection;
    }
}
