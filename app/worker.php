<?php

require_once __DIR__ . '/init.php';

use Appwrite\DSN\DSN;
use Appwrite\Extend\Exception;
use Appwrite\URL\URL as AppwriteURL;
use Swoole\Runtime;
use Utopia\App;
use Utopia\Cache\Adapter\Sharding;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Queue\Server;
use Utopia\Registry\Registry;
use Utopia\Queue;

global $register;

Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

Server::setResource('register', fn() => $register);

Server::setResource('dbForConsole', function (Cache $cache, Registry $register) {
    $pools = $register->get('pools');
    $dbAdapter = $pools
        ->get('console')
        ->pop()
        ->getResource()
    ;

    $database = new Database($dbAdapter, $cache);
    $database->setNamespace('console');

    return $database;
}, ['cache', 'register']);

Server::setResource('cache', function (Registry $register) {
    $pools = $register->get('pools');
    $list = Config::getParam('pools-cache', []);
    $adapters = [];

    foreach ($list as $value) {
        $adapters[] = $pools
            ->get($value)
            ->pop()
            ->getResource()
        ;
    }

    return new Cache(new Sharding($adapters));
}, ['register']);

App::setResource('logger', function ($register) {
    return $register->get('logger');
}, ['register']);


// Todo better job to inject the client as a resource
$fallbackForRedis = AppwriteURL::unparse([
    'scheme' => 'redis',
    'host' => App::getEnv('_APP_REDIS_HOST', 'redis'),
    'port' => App::getEnv('_APP_REDIS_PORT', '6379'),
    'user' => App::getEnv('_APP_REDIS_USER', ''),
    'pass' => App::getEnv('_APP_REDIS_PASS', ''),
]);

$connection = App::getEnv('_APP_CONNECTIONS_QUEUE', $fallbackForRedis);
$dsns = explode(',', $connection ?? '');

if (empty($dsns)) {
    throw new Exception(Exception::GENERAL_SERVER_ERROR);
}

$dsn = explode('=', $dsns[0]);
$dsn = $dsn[1] ?? '';
$dsn = new DSN($dsn);

$redisConnection = new Queue\Connection\Redis($dsn->getHost(), $dsn->getPort());
$workerNumber = swoole_cpu_num() * intval(App::getEnv('_APP_WORKER_PER_CORE', 6));
$workerNumber = 1;
