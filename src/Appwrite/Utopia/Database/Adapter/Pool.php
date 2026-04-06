<?php

namespace Appwrite\Utopia\Database\Adapter;

use Utopia\Database\Database;

class Pool extends \Utopia\Database\Adapter\Pool
{
    public function setTimeout(int $milliseconds, string $event = Database::EVENT_ALL): void
    {
        $this->timeout = $milliseconds;

        $this->delegate(__FUNCTION__, \func_get_args());
    }

    public function clearTimeout(string $event = Database::EVENT_ALL): void
    {
        $this->timeout = 0;

        $this->delegate(__FUNCTION__, \func_get_args());
    }
}
