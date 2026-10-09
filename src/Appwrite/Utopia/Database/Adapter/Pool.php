<?php

namespace Appwrite\Utopia\Database\Adapter;

use Utopia\Database\Adapter\Pool as DatabasePool;
use Utopia\Pools\Pool as UtopiaPool;

class Pool extends DatabasePool
{
    #[\Override]
    public function hostname(): string
    {
        return $this->hostname !== '' ? $this->hostname : parent::hostname();
    }

    /**
     * @return UtopiaPool<covariant \Utopia\Database\Adapter>
     */
    public function getPool(): UtopiaPool
    {
        return $this->pool;
    }
}
