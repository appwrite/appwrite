<?php

namespace Appwrite\Utopia\Pools;

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
     * @return self
     */
    public function remove(string $id): self
    {
        unset($this->connections[$id]);
        return $this;
    }

    public function count(): int
    {
        return \count($this->connections);
    }

    /**
     * @return self
     * @throws \Exception
     */
    public function reclaim(): self
    {
        foreach ($this->connections as $id => $connection) {
            $connection->reclaim();
            unset($this->connections[$id]);
        }

        return $this;
    }
}
