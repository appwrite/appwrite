<?php

use Appwrite\Resque\Worker;
use Utopia\Cache\Adapter\Redis as RedisCache;
use Utopia\Cache\Cache;
use Utopia\CLI\Console;

require_once __DIR__ . '/../init.php';

Console::title('Syncs in V1 Worker');
Console::success(APP_NAME . ' syncs in worker v1 has started');

class SyncsInV1 extends Worker
{
    protected array $errors = [];

    public function getName(): string
    {
        return "syncs-in";
    }

    public function init(): void
    {
    }

    public function run(): void
    {
        if (!empty($this->args['key'])) {
            //var_dump('Purging -> ' . $this->args['key'] . ' from Redis cache');
            $this->getCache()->purge($this->args['key']);
        }
    }

    /**
     * Get  cache
     * @return RedisCache
     * @throws Exception
     */
    private function getCache(): RedisCache
    {
        global $register;

        return new RedisCache($register->get('cache'));
    }



    public function shutdown(): void
    {
    }
}
