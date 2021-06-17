<?php

use Appwrite\Database\Validator\Authorization;
use Appwrite\Resque\Worker;
use Utopia\Abuse\Abuse;
use Utopia\Abuse\Adapters\TimeLimit;
use Utopia\Audit\Audit;
use Utopia\Cache\Cache;
use Utopia\Cache\Adapter\Redis as RedisCache;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Storage\Device\Local;

require_once __DIR__.'/../init.php';

Console::title('Database V1 Worker');
Console::success(APP_NAME.' database worker v1 has started'."\n");

class DeletesV1 extends Worker
{
    public $args = [];

    public function init(): void
    {
    }

    public function run(): void
    {
    }

    public function shutdown(): void
    {
    }
}
