<?php

namespace Appwrite\Utopia\Database;

use Utopia\App;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter;
use Utopia\Database\Database as UDatabase;

class Database extends UDatabase
{
    /**
     * Expression constructor
     *
     * @param Adapter $adapter
     * @param Cache $cache
     * @param array $filters
     * @throws \Exception
     */
    public function __construct(Adapter $adapter, Cache $cache, array $filters = [])
    {
        parent::__construct($adapter, $cache, $filters);
    }

    /**
     * Get Limit Count.
     *
     * Returns document limit count from environment.
     *
     * @return string
     */
    public function getLimitCount()
    {
        return App::getEnv('_APP_DB_LIMIT_COUNT', APP_LIMIT_COUNT);
    }
}
