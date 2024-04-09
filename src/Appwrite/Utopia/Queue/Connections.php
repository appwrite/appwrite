<?php

namespace Appwrite\Utopia\Queue;

use Utopia\Pools\Connection;

class Connections
{
    /**
     * @var array<Connection>
     */
    protected array $connections = [];

    /**
     * @param Connection $connection
     * @return self
     */
    public function add(Connection $connection): self
    {
        $this->connections[$connection->getID()] = $connection;
        return $this;
    }

    /**
     * @param string $id
     * @return Connection
     * @throws \Exception
     */
    public function get(string $id): Connection
    {
        return $this->connections[$id] ??  throw new \Exception("Connection '{$id}'  not found");
    }

    /**
     * @param string $id
     * @return self
     */
    public function remove(string $id): self
    {
        unset($this->connections[$id]);
        return $this;
    }

    /**
     * @return self
     * @throws \Exception
     */
    public function reclaim(): self
    {
        foreach ($this->connections as $connection) {
            $connection->reclaim();
        }

        return $this;
    }
}
