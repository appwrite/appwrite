<?php

namespace Appwrite\Utopia\Queue;

class Connections
{
    /**
     * @var array
     */
    protected array $connections = [];

    /**
     * @param mixed $connection
     * @return self
     */
    public function add(mixed $connection, $pool): self
    {
        $this->connections[] = ['connection' => $connection, 'pool' => $pool];
        return $this;
    }

    /**
     * @return self
     */
    public function reclaim(): self
    {
        foreach ($this->connections as $id => $resource) {
            $pool = $resource['pool'];
            $connection = $resource['connection'];
            $pool->put($connection);
            unset($this->connections[$id]);
        }

        return $this;
    }
}
