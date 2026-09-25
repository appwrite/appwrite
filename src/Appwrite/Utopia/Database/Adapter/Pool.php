<?php

namespace Appwrite\Utopia\Database\Adapter;

use Utopia\Database\Adapter\Pool as DatabasePool;
use Utopia\Pools\Pool as UtopiaPool;

class Pool extends DatabasePool
{
    public function getHostname(): string
    {
        return $this->hostname !== '' ? $this->hostname : parent::getHostname();
    }

    /**
     * @return UtopiaPool<covariant \Utopia\Database\Adapter>
     */
    public function getPool(): UtopiaPool
    {
        return $this->pool;
    }
}
